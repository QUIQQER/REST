define('package/quiqqer/rest/bin/settings/OpenApiPublication', [
    'qui/controls/Control',
    'Ajax',
    'Locale',
    'css!package/quiqqer/rest/bin/settings/OpenApiPublication.css'
], function (QUIControl, QUIAjax, QUILocale) {
    'use strict';

    const packageName = 'quiqqer/rest';
    const translate = (key) => QUILocale.get(packageName, 'settings.openapi.' + key);

    return new Class({
        Extends: QUIControl,
        Type: 'package/quiqqer/rest/bin/settings/OpenApiPublication',

        initialize: function (options) {
            this.parent(options);

            this.addEvents({
                onImport: () => this.importSelection(),
                onDestroy: () => this.Content?.remove()
            });
        },

        importSelection: function () {
            this.Input = this.getElm();
            this.Content = document.createElement('div');
            this.Content.className = 'quiqqer-rest-openapi';
            this.Status = document.createElement('p');
            this.Status.className = 'quiqqer-rest-openapi__status';
            this.Status.setAttribute('aria-live', 'polite');
            this.Status.textContent = translate('loading');
            this.Content.append(this.Status);
            this.Input.after(this.Content);
            this.groups = new Map();
            this.selectionError = false;

            let selection = {};
            try {
                selection = JSON.parse(this.Input.value || '{}') || {};
            } catch {
                this.Status.textContent = translate('invalidSelection');
            }

            QUIAjax.get('package_quiqqer_rest_ajax_openapi_options', (providers) => {
                if (!this.Content.isConnected) {
                    return;
                }

                this.Status.textContent = '';
                const entries = Object.entries(providers).sort(([, left], [, right]) => (
                    left.title.localeCompare(right.title)
                ));

                for (const [name, provider] of entries) {
                    const selected = Object.hasOwn(selection, name) ? selection[name] : [];
                    this.addProvider(name, provider, selected);
                }

                const unavailable = Object.keys(selection).filter((name) => !this.groups.has(name));

                for (const name of unavailable) {
                    this.addProvider(name, {title: name, version: '', operations: []}, selection[name], true);
                }

                if (this.groups.size === 0) {
                    this.Status.textContent = translate('empty');
                }
            }, {
                package: packageName,
                onError: () => {
                    this.Status.textContent = translate('loadError');
                }
            });
        },

        addProvider: function (name, provider, selected, unavailable = false) {
            const section = document.createElement('section');
            section.className = 'quiqqer-rest-openapi__provider';

            const header = document.createElement('div');
            header.className = 'quiqqer-rest-openapi__header';
            section.append(header);

            const title = unavailable ? provider.title + ' (' + translate('unavailable') + ')' : provider.title;
            const providerField = document.createElement('input');
            providerField.type = 'checkbox';
            providerField.setAttribute('aria-label', translate('wholeApi') + ' · ' + provider.title);

            const providerLabel = document.createElement('label');
            providerLabel.className = 'quiqqer-rest-openapi__whole';
            providerLabel.append(providerField, translate('wholeApi'));

            const expand = document.createElement('button');
            expand.type = 'button';
            expand.className = 'quiqqer-rest-openapi__expand';
            expand.setAttribute('aria-expanded', 'false');

            const chevron = document.createElement('span');
            chevron.className = 'fa fa-chevron-right quiqqer-rest-openapi__chevron';
            chevron.setAttribute('aria-hidden', 'true');

            const titleElement = document.createElement('span');
            titleElement.className = 'quiqqer-rest-openapi__title';
            titleElement.textContent = title;

            const count = document.createElement('span');
            count.className = 'quiqqer-rest-openapi__count';

            expand.append(chevron, titleElement, count);
            header.append(expand, providerLabel);

            const group = {
                providerField,
                count,
                operations: new Map(),
                available: new Set(provider.operations),
                whole: selected === true,
                version: provider.version,
                unavailable
            };
            this.groups.set(name, group);

            if (unavailable) {
                group.whole = true;
                group.originalSelection = selected;
            }

            const entryPoints = new Set(provider.operations);
            if (Array.isArray(selected)) {
                selected.forEach((operation) => entryPoints.add(operation));
            }

            if (entryPoints.size > 0 && !unavailable) {
                const list = document.createElement('ul');
                list.className = 'quiqqer-rest-openapi__endpoints';
                list.id = this.getId() + '-endpoints-' + this.groups.size;
                list.hidden = true;
                expand.setAttribute('aria-controls', list.id);

                expand.addEventListener('click', () => {
                    list.hidden = !list.hidden;
                    expand.setAttribute('aria-expanded', String(!list.hidden));
                });

                for (const operation of entryPoints) {
                    const item = document.createElement('li');
                    const label = document.createElement('label');
                    label.className = 'quiqqer-rest-openapi__endpoint';

                    const field = document.createElement('input');
                    field.type = 'checkbox';
                    const missing = !group.available.has(operation);
                    field.checked = group.whole || (Array.isArray(selected) && selected.includes(operation));
                    group.operations.set(operation, field);

                    const separator = operation.indexOf(' ');
                    const method = document.createElement('span');
                    method.className = 'quiqqer-rest-openapi__method';
                    method.textContent = operation.slice(0, separator);

                    const path = document.createElement('code');
                    path.textContent = operation.slice(separator + 1) + (missing ? ' (' + translate('unavailable') + ')' : '');
                    label.append(field, method, path);
                    item.append(label);
                    list.append(item);

                    field.addEventListener('change', () => {
                        group.whole = false;
                        this.updateSelection();
                    });
                }

                section.append(list);
            } else {
                titleElement.classList.add('quiqqer-rest-openapi__title--unavailable');
                expand.replaceWith(titleElement);
            }

            providerField.addEventListener('change', () => {
                group.whole = providerField.checked;

                for (const [operation, field] of group.operations) {
                    field.checked = group.whole && group.available.has(operation);
                }

                this.updateSelection();
            });

            this.updateProviderCheckbox(group);
            this.Content.append(section);
        },

        updateProviderCheckbox: function (group) {
            const selected = Array.from(group.operations.values()).filter((field) => field.checked).length;
            group.providerField.checked = group.whole;
            group.providerField.indeterminate = !group.whole && selected > 0;
            group.count.textContent = selected > 0
                ? selected + ' / ' + group.available.size + ' ' + translate('endpoints')
                : group.available.size + ' ' + translate('endpoints');
        },

        updateSelection: function () {
            const selection = Object.create(null);
            const selectedVersions = new Set();
            const pathTemplates = new Map();
            const selectedOperations = new Set();
            let error = '';

            for (const [name, group] of this.groups) {
                this.updateProviderCheckbox(group);
                const operations = Array.from(group.operations).filter(([, field]) => field.checked)
                    .map(([operation]) => operation);

                if (group.unavailable && group.whole) {
                    selection[name] = group.originalSelection;
                    error = translate('unavailable');
                    continue;
                }

                if (operations.length === 0) {
                    continue;
                }

                selection[name] = group.whole ? true : operations;
                selectedVersions.add(group.version.split('.').slice(0, 2).join('.'));

                for (const operation of operations) {
                    const path = operation.slice(operation.indexOf(' ') + 1);
                    const template = path.replace(/\{[^}]+\}/g, '{}');
                    const existingPath = pathTemplates.get(template);

                    if (!group.available.has(operation)) {
                        error = translate('unavailable') + ' ' + operation;
                    } else if (selectedOperations.has(operation) || (existingPath && existingPath !== path)) {
                        error = translate('conflict') + ' ' + operation;
                    }

                    selectedOperations.add(operation);
                    pathTemplates.set(template, path);
                }
            }

            if (selectedVersions.size > 1) {
                error = translate('versionConflict');
            }

            this.selectionError = error !== '';
            this.Status.textContent = error;

            if (!this.selectionError) {
                this.Input.value = JSON.stringify(selection);
            }
        },

        save: function () {
            if (this.selectionError) {
                return Promise.reject(new Error(this.Status.textContent));
            }

            if (!this.Input.value) {
                return Promise.resolve();
            }

            return new Promise((resolve, reject) => {
                QUIAjax.post('package_quiqqer_rest_ajax_openapi_validate', ({error}) => {
                    if (error) {
                        this.Status.textContent = error;
                        reject(new Error(error));
                    } else {
                        resolve();
                    }
                }, {
                    package: packageName,
                    selection: this.Input.value,
                    onError: (error) => {
                        this.Status.textContent = translate('validationError');
                        reject(error);
                    }
                });
            });
        }
    });
});
