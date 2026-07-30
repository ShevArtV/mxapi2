/**
 * Каталог эндпоинтов mxApi.
 *
 * Внутри страницы менеджера — обычный DOM без ExtJS-виджетов: справочник
 * «маршрут → права → параметры» это документ, а не таблица данных, и на гриде
 * он читается хуже, чем списком с раскрытием.
 */
var MxApi = MxApi || {};

MxApi.state = {
    endpoints: [],
    filtered: [],
    expanded: {},
    query: '',
    provider: ''
};

MxApi.init = function () {
    var root = document.getElementById('mxapi-catalog');
    if (!root) {
        return;
    }

    root.innerHTML = '<div class="mxapi-loading">Загрузка каталога…</div>';
    MxApi.load(root);
};

MxApi.load = function (root) {
    var request = new XMLHttpRequest();
    request.open('POST', MxApi.config.connector_url, true);
    request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    request.onload = function () {
        var response;
        try {
            response = JSON.parse(request.responseText);
        } catch (error) {
            root.innerHTML = '<div class="mxapi-error">Не удалось разобрать ответ сервера.</div>';
            return;
        }

        if (!response.success) {
            root.innerHTML = '<div class="mxapi-error">' + MxApi.escape(response.message || 'Ошибка загрузки каталога.') + '</div>';
            return;
        }

        MxApi.state.endpoints = response.results || [];
        MxApi.render(root);
    };

    request.onerror = function () {
        root.innerHTML = '<div class="mxapi-error">Соединение с коннектором не установлено.</div>';
    };

    request.send('action=mgr/endpoints/getlist');
};

MxApi.render = function (root) {
    MxApi.applyFilters();

    root.innerHTML = MxApi.renderHeader() + MxApi.renderList();
    MxApi.bind(root);
};

MxApi.renderHeader = function () {
    var providers = {};
    MxApi.state.endpoints.forEach(function (endpoint) {
        providers[endpoint.provider] = true;
    });

    var options = ['<option value="">Все источники</option>'];
    Object.keys(providers).sort().forEach(function (provider) {
        options.push('<option value="' + MxApi.escape(provider) + '"' +
            (MxApi.state.provider === provider ? ' selected' : '') + '>' + MxApi.escape(provider) + '</option>');
    });

    var disabled = MxApi.config.enabled
        ? ''
        : '<div class="mxapi-warning">API выключен настройкой <code>mxapi.enabled</code>: запросы получают 503.</div>';

    return '' +
        disabled +
        '<div class="mxapi-toolbar">' +
            '<input type="search" id="mxapi-search" class="mxapi-search" placeholder="Поиск по маршруту, scope, праву…" value="' + MxApi.escape(MxApi.state.query) + '">' +
            '<select id="mxapi-provider" class="mxapi-provider">' + options.join('') + '</select>' +
            '<a class="mxapi-button" href="' + MxApi.config.connector_url + '?action=mgr/openapi/get&download=1" target="_blank" rel="noopener">Скачать OpenAPI</a>' +
        '</div>' +
        '<div class="mxapi-base">Базовый адрес: <code>' + MxApi.escape(MxApi.config.site_url + MxApi.config.route_prefix) + '</code> · ' +
            'эндпоинтов: ' + MxApi.state.filtered.length + ' из ' + MxApi.state.endpoints.length +
        '</div>';
};

MxApi.applyFilters = function () {
    var query = MxApi.state.query.toLowerCase();
    var provider = MxApi.state.provider;

    MxApi.state.filtered = MxApi.state.endpoints.filter(function (endpoint) {
        if (provider && endpoint.provider !== provider) {
            return false;
        }
        if (!query) {
            return true;
        }

        return [endpoint.id, endpoint.title, endpoint.path, endpoint.scope, endpoint.permission, endpoint.description]
            .join(' ').toLowerCase().indexOf(query) !== -1;
    });
};

MxApi.renderList = function () {
    if (!MxApi.state.filtered.length) {
        return '<div class="mxapi-empty">Ничего не найдено.</div>';
    }

    return '<div class="mxapi-list">' + MxApi.state.filtered.map(MxApi.renderItem).join('') + '</div>';
};

MxApi.renderItem = function (endpoint) {
    var expanded = !!MxApi.state.expanded[endpoint.id];

    var badges = endpoint.methods.map(function (method) {
        return '<span class="mxapi-method mxapi-method-' + method.toLowerCase() + '">' + MxApi.escape(method) + '</span>';
    }).join('');

    if (!endpoint.public) {
        badges += '<span class="mxapi-tag mxapi-tag-internal" title="Служебный: не отдаётся во внешний каталог и OpenAPI">служебный</span>';
    }
    if (endpoint.write) {
        badges += '<span class="mxapi-tag mxapi-tag-write" title="Изменяет данные">запись</span>';
    }
    if (endpoint.deprecated) {
        badges += '<span class="mxapi-tag mxapi-tag-deprecated">устарел</span>';
    }

    return '' +
        '<div class="mxapi-item' + (expanded ? ' mxapi-item-open' : '') + '" data-id="' + MxApi.escape(endpoint.id) + '">' +
            '<div class="mxapi-item-head" data-toggle="' + MxApi.escape(endpoint.id) + '">' +
                '<span class="mxapi-methods">' + badges + '</span>' +
                '<code class="mxapi-path">' + MxApi.escape(endpoint.path) + '</code>' +
                '<span class="mxapi-title">' + MxApi.escape(endpoint.title) + '</span>' +
                '<span class="mxapi-provider-tag">' + MxApi.escape(endpoint.provider) + '</span>' +
            '</div>' +
            (expanded ? MxApi.renderDetails(endpoint) : '') +
        '</div>';
};

/**
 * Контекст, в котором выполняется эндпоинт. Права процессоров принадлежат
 * политике контекста, поэтому администратору это видеть так же важно, как право.
 */
MxApi.contextLabel = function (endpoint) {
    if (endpoint.modx_context === 'request') {
        return 'из запроса (<code>X-MxApi-Context</code>)';
    }

    if (endpoint.modx_context) {
        return '<code>' + MxApi.escape(endpoint.modx_context) + '</code>';
    }

    return '<span class="mxapi-muted">по умолчанию (mxapi.context)</span>';
};

MxApi.renderDetails = function (endpoint) {
    var rows = [
        ['Идентификатор', '<code>' + MxApi.escape(endpoint.id) + '</code>'],
        ['Полный адрес', '<code>' + MxApi.escape(MxApi.config.site_url + MxApi.config.route_prefix + endpoint.path) + '</code>'],
        ['Scope', endpoint.scope ? '<code>' + MxApi.escape(endpoint.scope) + '</code>' : '<span class="mxapi-muted">не требуется</span>'],
        ['Право MODX', endpoint.permission ? '<code>' + MxApi.escape(endpoint.permission) + '</code>' : '<span class="mxapi-muted">не требуется</span>'],
        ['Аутентификация', endpoint.auth === 'none' ? '<span class="mxapi-muted">не требуется</span>' : 'Bearer-токен'],
        ['Контекст MODX', MxApi.contextLabel(endpoint)],
        ['Источник', MxApi.escape(endpoint.provider)]
    ];

    if (endpoint.processor) {
        rows.push(['Процессор', '<code>' + MxApi.escape(endpoint.processor) + '</code>']);
    }

    var table = rows.map(function (row) {
        return '<tr><th>' + row[0] + '</th><td>' + row[1] + '</td></tr>';
    }).join('');

    return '' +
        '<div class="mxapi-details">' +
            (endpoint.description ? '<p class="mxapi-description">' + MxApi.escape(endpoint.description) + '</p>' : '') +
            '<table class="mxapi-meta">' + table + '</table>' +
            MxApi.renderParameters(endpoint) +
            MxApi.renderExample(endpoint) +
        '</div>';
};

MxApi.renderParameters = function (endpoint) {
    if (!endpoint.parameters || !endpoint.parameters.length) {
        return '<p class="mxapi-muted">Параметров нет.</p>';
    }

    var rows = endpoint.parameters.map(function (parameter) {
        var extras = [];
        if (parameter.default !== null && parameter.default !== undefined) {
            extras.push('по умолчанию <code>' + MxApi.escape(String(parameter.default)) + '</code>');
        }
        if (parameter.enum && parameter.enum.length) {
            extras.push('значения: ' + parameter.enum.map(MxApi.escape).join(', '));
        }

        return '<tr>' +
            '<td><code>' + MxApi.escape(parameter.name) + '</code></td>' +
            '<td>' + MxApi.escape(parameter.type) + '</td>' +
            '<td>' + MxApi.escape(parameter.in) + '</td>' +
            '<td>' + (parameter.required ? 'да' : 'нет') + '</td>' +
            '<td>' + MxApi.escape(parameter.description || '') +
                (extras.length ? '<div class="mxapi-muted">' + extras.join(' · ') + '</div>' : '') +
            '</td>' +
        '</tr>';
    }).join('');

    return '' +
        '<table class="mxapi-params">' +
            '<thead><tr><th>Параметр</th><th>Тип</th><th>Где</th><th>Обязателен</th><th>Описание</th></tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
        '</table>';
};

MxApi.renderExample = function (endpoint) {
    var url = MxApi.config.site_url + MxApi.config.route_prefix + endpoint.path.replace(/\[|\]/g, '').replace(/\{(\w+)[^}]*\}/g, '{$1}');
    var method = endpoint.methods[0] || 'GET';

    var command = 'curl -X ' + method + " '" + url + "'";
    if (endpoint.auth !== 'none') {
        command += " \\\n  -H 'Authorization: Bearer <token>'";
    }
    if (method !== 'GET') {
        command += " \\\n  -H 'Content-Type: application/json' \\\n  -d '{}'";
    }

    return '<pre class="mxapi-curl">' + MxApi.escape(command) + '</pre>';
};

MxApi.bind = function (root) {
    var search = document.getElementById('mxapi-search');
    if (search) {
        search.addEventListener('input', function () {
            MxApi.state.query = this.value;
            MxApi.render(root);
            var field = document.getElementById('mxapi-search');
            if (field) {
                field.focus();
                field.setSelectionRange(field.value.length, field.value.length);
            }
        });
    }

    var provider = document.getElementById('mxapi-provider');
    if (provider) {
        provider.addEventListener('change', function () {
            MxApi.state.provider = this.value;
            MxApi.render(root);
        });
    }

    root.querySelectorAll('[data-toggle]').forEach(function (element) {
        element.addEventListener('click', function () {
            var id = this.getAttribute('data-toggle');
            MxApi.state.expanded[id] = !MxApi.state.expanded[id];
            MxApi.render(root);
        });
    });
};

MxApi.escape = function (value) {
    return String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
};
