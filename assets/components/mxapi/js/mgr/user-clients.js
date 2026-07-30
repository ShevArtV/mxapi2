/**
 * Вкладка «mxApi» на странице правки пользователя: клиенты интеграции.
 *
 * В отличие от каталога эндпоинтов (mxapi.js, обычный DOM) здесь всё на ExtJS:
 * виджет живёт внутри чужой панели менеджера, а свой DOM в такой панели ломает
 * её раскладку и скролл. Цена — местами многословный Ext 3, зато страница
 * пользователя ведёт себя как обычно.
 */
var MxApiClients = MxApiClients || {};

MxApiClients.config = window.MxApiUserClients || {};

/**
 * Значения поля token_ttl. Совпадают с ClientRecord: 0 — общий TTL сайта,
 * -1 — бессрочно, положительное число — секунды.
 */
MxApiClients.TTL_SITE = 0;
MxApiClients.TTL_NEVER = -1;

/* ------------------------------------------------------------------ *
 *  Окно с секретом
 * ------------------------------------------------------------------ */

/**
 * Секрет существует только здесь: в базе лежит хэш, повторно показать его
 * невозможно. Поэтому окно модальное, с явным предупреждением и копированием
 * в один клик — закрыть его не скопировав означает перевыпуск.
 */
MxApiClients.showSecret = function (data) {
    var id = Ext.id();

    var window_ = new Ext.Window({
        title: _('mxapi_client_secret_title'),
        modal: true,
        width: 560,
        autoHeight: true,
        cls: 'mxapi-secret-window',
        bodyStyle: 'padding:15px',
        html: '<p class="mxapi-secret-warning">' + _('mxapi_client_secret_warning') + '</p>'
            + '<p class="mxapi-secret-label">client_id</p>'
            + '<div class="mxapi-secret-value">' + Ext.util.Format.htmlEncode(data.client_key) + '</div>'
            + '<p class="mxapi-secret-label">client_secret</p>'
            + '<div class="mxapi-secret-value" id="' + id + '">' + Ext.util.Format.htmlEncode(data.client_secret) + '</div>',
        buttons: [{
            text: _('mxapi_client_secret_copy'),
            handler: function () {
                MxApiClients.copyToClipboard(data.client_secret);
            }
        }, {
            text: _('close'),
            handler: function () {
                window_.close();
            }
        }]
    });

    window_.show();
};

/**
 * navigator.clipboard доступен только на https и в свежих браузерах, а менеджер
 * часто открыт по http на стенде — поэтому fallback через временное поле.
 *
 * @param {String} text
 */
MxApiClients.copyToClipboard = function (text) {
    var done = function () {
        MODx.msg.status({message: _('mxapi_client_secret_copied'), delay: 2});
    };

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done);

        return;
    }

    var area = document.createElement('textarea');
    area.value = text;
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try {
        document.execCommand('copy');
        done();
    } catch (e) {
        // Копировать нечем — секрет всё равно на экране, выделяется руками.
    }
    document.body.removeChild(area);
};

/* ------------------------------------------------------------------ *
 *  Дерево scope
 * ------------------------------------------------------------------ */

/**
 * Отметить узел с учётом того, отрисован он или нет.
 *
 * ⚠️ В Ext 3 UI узла создаётся только при рендере, а рендерятся лишь дети
 * раскрытых веток. У свёрнутой группы getUI().checkbox не существует, и
 * toggleCheck() молча ничего не делает — каскад «отметить весь источник» не
 * работал ровно поэтому (поймано на стенде). Для нерендеренных узлов пишем
 * attributes.checked: чекбокс отрисуется по нему при раскрытии.
 *
 * @param {Ext.tree.TreeNode} node
 * @param {Boolean} checked
 */
MxApiClients.setNodeChecked = function (node, checked) {
    var ui = node.getUI();

    if (ui && ui.checkbox) {
        if (ui.isChecked() !== checked) {
            ui.toggleCheck(checked);
        }

        return;
    }

    node.attributes.checked = checked;
};

/**
 * Отмечен ли узел. Читаем attributes, а не UI: onCheckChange держит их в
 * актуальном состоянии, а UI у свёрнутых веток попросту нет.
 *
 * @param {Ext.tree.TreeNode} node
 * @return {Boolean}
 */
MxApiClients.isNodeChecked = function (node) {
    return !!node.attributes.checked;
};

/**
 * Дерево «все → источник → scope» с чекбоксами: три способа выбора, о которых
 * просили — всё сразу, весь источник, поштучно.
 *
 * @param {Array} selected Уже выданные scope (правка клиента).
 * @return {Ext.tree.TreePanel}
 */
MxApiClients.buildScopeTree = function (selected) {
    selected = selected || [];

    var tree = new Ext.tree.TreePanel({
        cls: 'mxapi-scope-tree',
        height: 260,
        autoScroll: true,
        animate: false,
        rootVisible: true,
        lines: false,
        border: true,
        root: new Ext.tree.TreeNode({
            text: _('mxapi_client_scopes_all'),
            expanded: true,
            checked: false
        })
    });

    // Ext 3 не умеет каскад чекбоксов и не знает промежуточного состояния:
    // родитель с частью выбранных детей показан снятым. Это осознанно принято —
    // раскрытые ветки при правке компенсируют потерю.
    // Клик по названию источника раскрывает и сворачивает его список. Стрелка
    // слева от узла — мишень в несколько пикселей, попасть в неё трудно, и
    // выглядит это как «список не открывается».
    tree.on('click', function (node) {
        if (node.childNodes.length) {
            node.toggle();
        }
    });

    tree.on('checkchange', function (node, checked) {
        node.cascade(function (child) {
            if (child !== node) {
                MxApiClients.setNodeChecked(child, checked);
            }
        });
    });

    tree.selectedScopes = selected;

    return tree;
};

/**
 * Наполнение дерева ответом процессора scopes.
 *
 * @param {Ext.tree.TreePanel} tree
 * @param {Object} data
 */
MxApiClients.fillScopeTree = function (tree, data) {
    var root = tree.getRootNode(),
        selected = tree.selectedScopes || [],
        groups = data.groups || [];

    while (root.firstChild) {
        root.removeChild(root.firstChild);
    }

    if (!groups.length) {
        root.setText(data.total_existing > 0
            ? _('mxapi_client_scopes_none_allowed')
            : _('mxapi_client_scopes_none_exist'));
        root.getUI().hideCheckbox();

        return;
    }

    Ext.each(groups, function (group) {
        var hasSelected = false,
            children = [];

        Ext.each(group.scopes, function (item) {
            var checked = selected.indexOf(item.scope) !== -1;
            if (checked) {
                hasSelected = true;
            }

            // ⚠️ Текст узла Ext экранирует при рендере — разметка внутри text
            // выводится как есть, тегами наружу (поймано на стенде). Поэтому
            // подпись обычным текстом, а полный список эндпоинтов — в подсказке.
            children.push({
                text: item.endpoints_text
                    ? item.scope + ' — ' + item.endpoints_text
                    : item.scope,
                qtip: item.endpoints_text,
                cls: 'mxapi-scope-leaf',
                scope: item.scope,
                checked: checked,
                leaf: true
            });
        });

        var groupNode = root.appendChild(new Ext.tree.TreeNode({
            text: Ext.util.Format.htmlEncode(group.provider),
            checked: false
        }));

        // ⚠️ Ext 3: конфиг children у TreeNode игнорируется — его разбирает
        // только TreeLoader при загрузке с сервера. Дерево строится в памяти,
        // поэтому узлы добавляются явно. Поймано на стенде: группы
        // отображались пустыми, хотя scope в ответе процессора были.
        Ext.each(children, function (child) {
            groupNode.appendChild(new Ext.tree.TreeNode(child));
        });

        // Ветка с выданными scope раскрыта: иначе при правке не видно, что
        // именно у клиента есть, пока не раскроешь все группы руками.
        if (hasSelected) {
            groupNode.expand();
        }
    });

    root.expand();
};

/**
 * Отмеченные листья дерева.
 *
 * @param {Ext.tree.TreePanel} tree
 * @return {Array}
 */
MxApiClients.collectScopes = function (tree) {
    var scopes = [];

    tree.getRootNode().cascade(function (node) {
        var scope = node.attributes.scope;
        if (scope && MxApiClients.isNodeChecked(node) && scopes.indexOf(scope) === -1) {
            // Один scope может встретиться в двух группах, если его объявляют
            // эндпоинты разных провайдеров — в запрос он должен уйти один раз.
            scopes.push(scope);
        }
    });

    return scopes;
};

/* ------------------------------------------------------------------ *
 *  Окно создания и правки
 * ------------------------------------------------------------------ */

MxApiClients.ClientWindow = function (config) {
    config = config || {};
    var record = config.record || {};

    this.isUpdate = !!record.id;
    this.scopeTree = MxApiClients.buildScopeTree(record.scopes || []);
    this.ttlField = new Ext.form.NumberField({
        name: 'token_ttl_seconds',
        fieldLabel: _('mxapi_client_ttl_seconds'),
        anchor: '60%',
        allowDecimals: false,
        allowNegative: false,
        minValue: 60,
        value: (record.token_ttl > 0) ? record.token_ttl : 86400,
        hidden: !(record.token_ttl > 0)
    });

    var ttlMode = MxApiClients.TTL_SITE;
    if (record.token_ttl === MxApiClients.TTL_NEVER) {
        ttlMode = MxApiClients.TTL_NEVER;
    } else if (record.token_ttl > 0) {
        ttlMode = 1;
    }

    this.ttlMode = new Ext.form.ComboBox({
        name: 'token_ttl_mode',
        fieldLabel: _('mxapi_client_ttl'),
        anchor: '60%',
        mode: 'local',
        triggerAction: 'all',
        editable: false,
        valueField: 'mode',
        displayField: 'label',
        value: ttlMode,
        store: new Ext.data.ArrayStore({
            fields: ['mode', 'label'],
            data: [
                [MxApiClients.TTL_SITE, _('mxapi_client_ttl_site')],
                [1, _('mxapi_client_ttl_custom')],
                [MxApiClients.TTL_NEVER, _('mxapi_client_ttl_never')]
            ]
        }),
        listeners: {
            select: {
                fn: function (combo, rec) {
                    var custom = rec.get('mode') === 1;
                    this.ttlField.setVisible(custom);
                    // Бессрочный токен — долгоживущий секрет без автоматического
                    // истечения, поэтому предупреждаем сразу, а не в доке.
                    this.neverWarning.setVisible(rec.get('mode') === MxApiClients.TTL_NEVER);
                    this.refreshLayout();
                },
                scope: this
            }
        }
    });

    this.neverWarning = new Ext.Panel({
        border: false,
        cls: 'mxapi-never-warning',
        hidden: ttlMode !== MxApiClients.TTL_NEVER,
        html: _('mxapi_client_ttl_never_warning')
    });

    Ext.applyIf(config, {
        title: this.isUpdate ? _('mxapi_client_window_update') : _('mxapi_client_window_create'),
        width: 640,
        modal: true,
        autoHeight: true,
        cls: 'mxapi-client-window',
        layout: 'form',
        labelWidth: 170,
        bodyStyle: 'padding:15px',
        items: [{
            xtype: 'textfield',
            name: 'name',
            id: 'mxapi-client-name-' + Ext.id(),
            fieldLabel: _('mxapi_client_name'),
            anchor: '100%',
            allowBlank: false,
            value: record.name || '',
            // Браузер охотно подставляет сюда сохранённые значения из чужих
            // форм — и клиент уезжает в базу с посторонним названием.
            autoCreate: {tag: 'input', type: 'text', autocomplete: 'off'}
        },
            this.ttlMode,
            this.ttlField,
            this.neverWarning,
            {
                xtype: 'panel',
                border: false,
                cls: 'mxapi-scope-caption',
                html: _('mxapi_client_scopes_caption')
            },
            this.scopeTree
        ],
        buttons: [{
            text: _('cancel'),
            handler: function () {
                this.close();
            },
            scope: this
        }, {
            text: _('save'),
            cls: 'primary-button',
            handler: this.submitForm,
            scope: this
        }]
    });

    MxApiClients.ClientWindow.superclass.constructor.call(this, config);

    this.record = record;
    this.nameField = this.items.itemAt(0);

    this.on('show', this.loadScopes, this);
};

Ext.extend(MxApiClients.ClientWindow, Ext.Window, {
    /**
     * Список доступных scope считается на сервере по правам ЦЕЛЕВОГО
     * пользователя, поэтому грузится при каждом открытии: права могли изменить
     * на соседней вкладке этой же страницы.
     */
    loadScopes: function () {
        MODx.Ajax.request({
            url: MxApiClients.config.connector_url,
            params: {
                action: 'mgr/client/scopes',
                user_id: MxApiClients.config.user_id
            },
            listeners: {
                success: {
                    fn: function (response) {
                        MxApiClients.fillScopeTree(this.scopeTree, response.object || {});
                    },
                    scope: this
                }
            }
        });
    },

    /**
     * Пересчёт раскладки после того, как поле «Секунд» скрылось или появилось.
     *
     * ⚠️ Метод НЕ называть syncShadow: такой метод есть у самого Ext.Window и
     * вызывается из onResize. Переопределив его вызовом syncSize(), получаем
     * syncShadow → syncSize → setSize → onResize → syncShadow и переполнение
     * стека при первом же открытии окна (поймано на стенде).
     * doLayout здесь достаточно: высоту окна пересчитывает autoHeight.
     */
    refreshLayout: function () {
        this.doLayout();
    },

    /**
     * @return {Number} Значение поля token_ttl для процессора.
     */
    resolveTtl: function () {
        var mode = this.ttlMode.getValue();

        if (mode === MxApiClients.TTL_NEVER || mode === String(MxApiClients.TTL_NEVER)) {
            return MxApiClients.TTL_NEVER;
        }
        if (mode === 1 || mode === '1') {
            return parseInt(this.ttlField.getValue(), 10) || 0;
        }

        return MxApiClients.TTL_SITE;
    },

    submitForm: function () {
        var name = String(this.nameField.getValue() || '').trim(),
            scopes = MxApiClients.collectScopes(this.scopeTree);

        if (name === '') {
            MODx.msg.alert(_('error'), _('mxapi_client_err_name_ns'));

            return;
        }
        if (!scopes.length) {
            MODx.msg.alert(_('error'), _('mxapi_client_err_scopes_ns'));

            return;
        }

        var params = {
            action: this.isUpdate ? 'mgr/client/update' : 'mgr/client/create',
            user_id: MxApiClients.config.user_id,
            name: name,
            scopes: Ext.encode(scopes),
            token_ttl: this.resolveTtl()
        };

        if (this.isUpdate) {
            params.id = this.record.id;
        }

        MODx.Ajax.request({
            url: MxApiClients.config.connector_url,
            params: params,
            listeners: {
                success: {
                    fn: function (response) {
                        // ⚠️ Порядок важен: close() уничтожает окно вместе с его
                        // слушателями, и событие, отправленное после него, никто
                        // уже не получит — грид не обновлялся (поймано на стенде).
                        this.fireEvent('mxapi-saved');
                        this.close();

                        if (response.object && response.object.client_secret) {
                            MxApiClients.showSecret(response.object);
                        }
                    },
                    scope: this
                }
            }
        });
    }
});

/* ------------------------------------------------------------------ *
 *  Грид клиентов
 * ------------------------------------------------------------------ */

MxApiClients.Grid = function (config) {
    config = config || {};

    Ext.applyIf(config, {
        url: MxApiClients.config.connector_url,
        baseParams: {
            action: 'mgr/client/getlist',
            user_id: MxApiClients.config.user_id
        },
        fields: [
            'id', 'name', 'client_key', 'scopes', 'scopes_text', 'token_ttl',
            'active', 'tokens_active', 'createdon', 'createdon_text'
        ],
        paging: false,
        autoHeight: true,
        // forceFit ужимает колонки под ширину грида — и колонка действий
        // обрезалась вместе с кнопками. Ширину ей держит fixed: true, который
        // forceFit не трогает, а данные ужимаются как обычно.
        viewConfig: {forceFit: true},
        columns: [{
            header: _('mxapi_client_name'),
            dataIndex: 'name',
            width: 140,
            renderer: function (value, meta, record) {
                var text = Ext.util.Format.htmlEncode(value);

                return record.data.active
                    ? '<span ext:qtip="' + text + '">' + text + '</span>'
                    : '<span class="mxapi-client-inactive" ext:qtip="' + text + '">' + text + '</span>';
            }
        }, {
            header: _('mxapi_client_key'),
            dataIndex: 'client_key',
            width: 165,
            renderer: function (value) {
                // Колонки узкие, значения режутся многоточием — полное показываем
                // подсказкой, иначе ключ из грида не прочитать.
                var text = Ext.util.Format.htmlEncode(value);

                return '<code ext:qtip="' + text + '">' + text + '</code>';
            }
        }, {
            header: _('mxapi_client_scopes'),
            dataIndex: 'scopes_text',
            width: 175,
            renderer: function (value) {
                var text = Ext.util.Format.htmlEncode(value);

                return '<span ext:qtip="' + text + '">' + text + '</span>';
            }
        }, {
            header: _('mxapi_client_ttl'),
            dataIndex: 'token_ttl',
            width: 105,
            renderer: function (value) {
                if (parseInt(value, 10) === MxApiClients.TTL_NEVER) {
                    return '<span class="mxapi-ttl-never">' + _('mxapi_client_ttl_never') + '</span>';
                }

                return parseInt(value, 10) > 0
                    ? value + ' ' + _('mxapi_client_ttl_sec_short')
                    : _('mxapi_client_ttl_site');
            }
        }, {
            header: _('mxapi_client_tokens'),
            dataIndex: 'tokens_active',
            width: 60
        }, {
            header: _('mxapi_client_createdon'),
            dataIndex: 'createdon_text',
            width: 95
        }, {
            // Те же действия, что в контекстном меню строки. Меню остаётся —
            // оно привычно в гриде MODX, — но видимые кнопки не требуют
            // догадаться, что по строке надо кликнуть правой.
            header: _('mxapi_client_actions'),
            dataIndex: 'id',
            id: 'mxapi-actions',
            width: 150,
            fixed: true,
            sortable: false,
            menuDisabled: true,
            renderer: function (value, meta, record) {
                // Иконки вместо подписей: четыре текстовые кнопки занимали
                // 380px из ~900 — половину грида под то, что и так понятно по
                // значку. Названия действий остаются в подсказке.
                var button = function (action, icon, title, cls) {
                    // title, а не только ext:qtip: у кнопки без подписи иначе нет
                    // доступного имени — скринридер прочитает пустую ссылку.
                    return '<a href="#" class="mxapi-act ' + (cls || '') + '"'
                        + ' data-act="' + action + '" title="' + title + '"'
                        + ' aria-label="' + title + '" ext:qtip="' + title + '">'
                        + '<i class="icon icon-' + icon + '"></i></a>';
                };

                return button('edit', 'pencil', _('mxapi_client_edit'))
                    + button('regenerate', 'key', _('mxapi_client_regenerate'))
                    + (record.data.active
                        ? button('toggle', 'ban', _('mxapi_client_deactivate'))
                        : button('toggle', 'check', _('mxapi_client_activate'), 'mxapi-act-ok'))
                    + button('remove', 'trash-o', _('mxapi_client_remove'), 'mxapi-act-danger');
            }
        }],
        tbar: [{
            text: _('mxapi_client_create'),
            cls: 'primary-button',
            handler: function () {
                this.openWindow({});
            },
            scope: this
        }]
    });

    MxApiClients.Grid.superclass.constructor.call(this, config);

    this.on('cellclick', this.onActionClick, this);
};

Ext.extend(MxApiClients.Grid, MODx.grid.Grid, {
    /**
     * Кнопки в колонке действий. Ext не даёт настоящих кнопок в ячейке без
     * лишнего веса, поэтому это ссылки, а клик ловится делегированием.
     *
     * @param {Ext.grid.GridPanel} grid
     * @param {Number} rowIndex
     * @param {Number} colIndex
     * @param {Ext.EventObject} e
     */
    onActionClick: function (grid, rowIndex, colIndex, e) {
        var link = e.getTarget('.mxapi-act', 3);
        if (!link) {
            return;
        }

        e.stopEvent();

        var record = this.store.getAt(rowIndex).data,
            action = link.getAttribute('data-act');

        if (action === 'edit') {
            this.openWindow(record);
        } else if (action === 'regenerate') {
            this.regenerate(record);
        } else if (action === 'toggle') {
            this.toggleActive(record);
        } else if (action === 'remove') {
            this.removeClient(record);
        }
    },

    /**
     * Меню строки. Отзыв токенов вынесен в перевыпуск отдельным вопросом:
     * плановая ротация секрета не должна ронять работающую интеграцию, а
     * компрометация — обязана.
     */
    getMenu: function () {
        var record = this.menu.record,
            menu = [{
                text: _('mxapi_client_edit'),
                handler: function () {
                    this.openWindow(record);
                },
                scope: this
            }, {
                text: _('mxapi_client_regenerate'),
                handler: function () {
                    this.regenerate(record);
                },
                scope: this
            }, '-', {
                text: record.active ? _('mxapi_client_deactivate') : _('mxapi_client_activate'),
                handler: function () {
                    this.toggleActive(record);
                },
                scope: this
            }, {
                text: _('mxapi_client_remove'),
                handler: function () {
                    this.removeClient(record);
                },
                scope: this
            }];

        return menu;
    },

    openWindow: function (record) {
        var window_ = new MxApiClients.ClientWindow({record: record});
        window_.on('mxapi-saved', function () {
            this.refresh();
        }, this);
        window_.show();
    },

    toggleActive: function (record) {
        MODx.Ajax.request({
            url: MxApiClients.config.connector_url,
            params: {
                action: 'mgr/client/update',
                user_id: MxApiClients.config.user_id,
                id: record.id,
                active: record.active ? 0 : 1
            },
            listeners: {
                success: {
                    fn: function () {
                        this.refresh();
                    },
                    scope: this
                }
            }
        });
    },

    regenerate: function (record) {
        var revoke = new Ext.form.Checkbox({
            boxLabel: _('mxapi_client_revoke_tokens'),
            checked: false
        });

        var window_ = new Ext.Window({
            title: _('mxapi_client_regenerate'),
            modal: true,
            width: 520,
            autoHeight: true,
            bodyStyle: 'padding:15px',
            items: [{
                xtype: 'panel',
                border: false,
                html: _('mxapi_client_regenerate_confirm')
            }, revoke],
            buttons: [{
                text: _('cancel'),
                handler: function () {
                    window_.close();
                }
            }, {
                text: _('mxapi_client_regenerate'),
                cls: 'primary-button',
                handler: function () {
                    MODx.Ajax.request({
                        url: MxApiClients.config.connector_url,
                        params: {
                            action: 'mgr/client/regenerate',
                            user_id: MxApiClients.config.user_id,
                            id: record.id,
                            revoke_tokens: revoke.getValue() ? 1 : 0
                        },
                        listeners: {
                            success: {
                                fn: function (response) {
                                    window_.close();
                                    this.refresh();
                                    MxApiClients.showSecret(response.object);
                                },
                                scope: this
                            }
                        }
                    });
                },
                scope: this
            }]
        });

        window_.show();
    },

    removeClient: function (record) {
        MODx.msg.confirm({
            title: _('mxapi_client_remove'),
            text: _('mxapi_client_remove_confirm'),
            url: MxApiClients.config.connector_url,
            params: {
                action: 'mgr/client/remove',
                user_id: MxApiClients.config.user_id,
                id: record.id
            },
            listeners: {
                success: {
                    fn: function () {
                        this.refresh();
                    },
                    scope: this
                }
            }
        });
    }
});

/* ------------------------------------------------------------------ *
 *  Встройка вкладки
 * ------------------------------------------------------------------ */

Ext.onReady(function () {
    if (!MxApiClients.config.user_id) {
        return;
    }

    // Панель вкладок пользователя объявлена в modx.panel.user.js под этим id.
    var attach = function (tabs) {
        var grid = new MxApiClients.Grid({});

        tabs.add({
            title: _('mxapi'),
            id: 'mxapi-user-clients-tab',
            layout: 'anchor',
            bodyStyle: 'padding:15px',
            cls: 'mxapi-user-tab',
            items: [{
                xtype: 'panel',
                border: false,
                cls: 'mxapi-tab-intro',
                html: _('mxapi_client_intro')
            }, grid],
            listeners: {
                // Ленивая загрузка: на страницу пользователя заходят и не ради
                // API, лишний запрос там ни к чему.
                activate: {
                    fn: function () {
                        if (!grid.store.getCount() && !grid.loadedOnce) {
                            grid.loadedOnce = true;
                            grid.store.load();
                        }
                    },
                    single: false
                }
            }
        });

        tabs.doLayout();
    };

    // ⚠️ Ext.ComponentMgr.onAvailable в Ext 3 срабатывает только на БУДУЩЕЕ
    // добавление компонента: он слушает событие add у коллекции и не проверяет,
    // не зарегистрирован ли компонент уже. Наш скрипт подключён последним
    // (addLastJavascript), поэтому его обработчик onReady выполняется после
    // того, как страница собрала панель, — и колбэк не вызвался бы никогда.
    // Проверено на стенде: вкладка не появлялась при полностью загруженном
    // виджете. Поэтому сначала пробуем взять готовую панель.
    var existing = Ext.getCmp('modx-user-tabs');
    if (existing) {
        attach(existing);

        return;
    }

    Ext.ComponentMgr.onAvailable('modx-user-tabs', attach);
});
