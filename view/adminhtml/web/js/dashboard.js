/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 *
 * Brand visibility dashboard.
 *
 * Every node is created with the DOM API and filled with .text(), never with
 * concatenated HTML, so provider answers cannot inject markup into the admin.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    var SIGNAL_LABELS = {
        mentioned: 'Mentioned',
        recommended: 'Recommended',
        url_cited: 'URL cited',
        first_result: 'First position',
        positive_sentiment: 'Positive tone',
        negative_sentiment: 'Negative tone'
    };

    /**
     * Build a table cell holding plain text.
     *
     * @param {String} text
     * @param {String} [className]
     * @returns {jQuery}
     */
    function cell(text, className) {
        var $td = $('<td/>').text(text === null || text === undefined ? '' : String(text));

        if (className) {
            $td.addClass(className);
        }

        return $td;
    }

    /**
     * Format a rate as a whole percentage.
     *
     * @param {Object} rates
     * @param {String} key
     * @returns {String}
     */
    function rate(rates, key) {
        var value = rates && rates[key] !== undefined ? Number(rates[key]) : 0;

        return Math.round(value) + '%';
    }

    /**
     * Append one definition pair to a description list.
     *
     * @param {jQuery} $list
     * @param {String} term
     * @param {String} value
     */
    function pair($list, term, value) {
        $list.append($('<dt/>').text(term));
        $list.append($('<dd/>').text(value));
    }

    return function (config, element) {
        var $root = $(element),
            $status = $root.find('[data-role="status"]'),
            pollTimer = null,
            pollStartedAt = 0;

        /**
         * Show a status message.
         *
         * @param {String} message
         * @param {String} kind
         */
        function setStatus(message, kind) {
            $status
                .removeClass('angeo-bv-status-error angeo-bv-status-info angeo-bv-status-success')
                .addClass('angeo-bv-status-' + (kind || 'info'))
                .text(message)
                .prop('hidden', false);
        }

        /**
         * Currently selected store view.
         *
         * @returns {Number}
         */
        function storeId() {
            return parseInt($root.find('[data-role="store-select"]').val(), 10) || 0;
        }

        /**
         * Show or hide a named panel.
         *
         * @param {String} role
         * @param {Boolean} visible
         */
        function togglePanel(role, visible) {
            $root.find('[data-role="' + role + '"]').prop('hidden', !visible);
        }

        /**
         * Render the score summary.
         *
         * @param {Object} data
         */
        function renderSummary(data) {
            var $meta = $root.find('[data-role="summary-meta"]').empty();

            $root.find('[data-role="score"]').text(data.overall_score + '/100');
            $root.find('[data-role="margin"]').text(
                data.score_margin ? '\u00B1' + data.score_margin : ''
            );
            $root.find('[data-role="grade"]')
                .attr('class', 'angeo-bv-grade angeo-bv-grade-' + String(data.grade).toLowerCase())
                .text(data.grade);

            pair($meta, $t('Run at'), data.created_at || '');
            pair($meta, $t('Samples per query'), String(data.samples || 1));
            pair($meta, $t('Brand'), config.brandName || '');
            pair($meta, $t('Domain'), config.brandDomain || '');

            togglePanel('summary', true);
        }

        /**
         * Render the signal list.
         *
         * @param {Object} rates
         */
        function renderSignals(rates) {
            var $list = $root.find('[data-role="signals"]').empty();

            Object.keys(SIGNAL_LABELS).forEach(function (key) {
                var value = rates && rates[key] !== undefined ? Number(rates[key]) : 0,
                    $item = $('<li/>').addClass('angeo-bv-signal');

                $item.append($('<span/>').addClass('angeo-bv-signal-label').text($t(SIGNAL_LABELS[key])));
                $item.append($('<span/>').addClass('angeo-bv-signal-value').text(Math.round(value) + '%'));
                $item.append(
                    $('<span/>').addClass('angeo-bv-bar').append(
                        $('<span/>').addClass('angeo-bv-bar-fill').css('width', Math.max(0, Math.min(100, value)) + '%')
                    )
                );
                $list.append($item);
            });

            togglePanel('signals-panel', true);
        }

        /**
         * Render the competitive block.
         *
         * @param {Object} data
         */
        function renderCompetitive(data) {
            var $list = $root.find('[data-role="competitive"]').empty(),
                competitive = data.competitive || {};

            pair($list, $t('Share of voice'), (competitive.share_of_voice || 0) + '%');
            pair($list, $t('Win rate'), (competitive.win_rate || 0) + '%');
            pair($list, $t('Accuracy issues'), String(competitive.accuracy_issues || 0));

            Object.keys(data.scores_by_provider || {}).forEach(function (provider) {
                var score = data.scores_by_provider[provider];

                pair($list, provider, score === null ? $t('no data') : score + '/100');
            });

            togglePanel('competitive-panel', true);
        }

        /**
         * Render the per-answer table.
         *
         * @param {Array} results
         */
        function renderResults(results) {
            var $body = $root.find('[data-role="results"]').empty();

            (results || []).forEach(function (item) {
                var $row = $('<tr/>'),
                    rates = item.signal_rates || {},
                    meta = item.meta || {};

                $row.append(cell(item.provider_label || item.provider_id));
                $row.append(cell(item.prompt_key));

                if (!item.success) {
                    $row.append(cell('0'));
                    $row.append(cell($t('Failed')).addClass('angeo-bv-error'));
                    $row.append($('<td/>').attr('colspan', 6).text(item.error || ''));
                    $body.append($row);

                    return;
                }

                $row.append(cell(String(item.samples)));
                $row.append(cell(item.score + (item.score_margin ? ' \u00B1' + item.score_margin : '')));
                $row.append(cell(rate(rates, 'mentioned')));
                $row.append(cell(rate(rates, 'recommended')));
                $row.append(cell(rate(rates, 'url_cited')));
                $row.append(cell(rate(rates, 'first_result')));
                $row.append(cell(meta.tone || '', 'angeo-bv-tone-' + (meta.tone || 'neutral')));

                $row.append(
                    $('<td/>').append(
                        $('<button/>')
                            .attr('type', 'button')
                            .addClass('action-secondary angeo-bv-toggle')
                            .text($t('Show'))
                            .on('click', function () {
                                var $detail = $row.next('.angeo-bv-detail-row');

                                $detail.toggleClass('angeo-bv-hidden');
                                $(this).text($detail.hasClass('angeo-bv-hidden') ? $t('Show') : $t('Hide'));
                            })
                    )
                );

                $body.append($row);
                $body.append(
                    $('<tr/>').addClass('angeo-bv-detail-row angeo-bv-hidden').append(
                        $('<td/>').attr('colspan', 10).append(
                            $('<p/>').addClass('angeo-bv-note').text($t('Prompt: ') + item.prompt),
                            $('<pre/>').addClass('angeo-bv-raw').text(item.response || '')
                        )
                    )
                );
            });

            togglePanel('results-panel', true);
        }

        /**
         * Render the recent runs table.
         *
         * @param {Array} rows
         */
        function renderHistory(rows) {
            var $body = $root.find('[data-role="history"]').empty();

            (rows || []).forEach(function (row) {
                var $row = $('<tr/>');

                $row.append(cell(row.created_at));
                $row.append(cell(row.overall_score + (row.score_margin ? ' \u00B1' + row.score_margin : '')));
                $row.append(cell(row.grade));
                $row.append(cell(row.share_of_voice + '%'));
                $row.append(cell(row.win_rate + '%'));
                $row.append(cell(row.triggered_by));
                $row.append(
                    $('<td/>').append(
                        $('<a/>')
                            .attr('href', config.urls.view + 'id/' + encodeURIComponent(row.id) + '/')
                            .text($t('View'))
                    )
                );

                $body.append($row);
            });
        }

        /**
         * Render the action plan.
         *
         * @param {Array} plan
         */
        function renderPlan(plan) {
            var $list = $root.find('[data-role="plan"]').empty();

            (plan || []).forEach(function (item) {
                $list.append(
                    $('<li/>').addClass('angeo-bv-plan-item angeo-bv-priority-' + item.priority).append(
                        $('<strong/>').text(item.title),
                        $('<span/>').addClass('angeo-bv-plan-detail').text(item.detail)
                    )
                );
            });

            togglePanel('plan-panel', true);
        }

        /**
         * Stop polling.
         */
        function stopPolling() {
            if (pollTimer) {
                window.clearTimeout(pollTimer);
                pollTimer = null;
            }
        }

        /**
         * Poll the status endpoint until the run finishes.
         *
         * @param {Number} id
         */
        function poll(id) {
            if (Date.now() - pollStartedAt > config.pollTimeoutMs) {
                setStatus($t('The run is taking longer than expected. Check the audit history later.'), 'error');
                stopPolling();

                return;
            }

            $.ajax({
                url: config.urls.status,
                type: 'GET',
                dataType: 'json',
                data: { id: id }
            }).done(function (response) {
                if (!response.success) {
                    setStatus(response.message || $t('The run could not be read.'), 'error');
                    stopPolling();

                    return;
                }

                if (response.status === 'error') {
                    setStatus(response.error || $t('The run failed.'), 'error');
                    stopPolling();

                    return;
                }

                if (response.status === 'complete') {
                    setStatus($t('Run complete.'), 'success');
                    stopPolling();
                    renderSummary(response);
                    renderSignals(response.signal_rates);
                    renderCompetitive(response);
                    renderResults(response.results);
                    loadHistory();

                    return;
                }

                setStatus($t('Run queued. Waiting for the consumer to finish…'), 'info');
                pollTimer = window.setTimeout(function () {
                    poll(id);
                }, config.pollIntervalMs);
            }).fail(function () {
                setStatus($t('Could not reach the server while polling.'), 'error');
                stopPolling();
            });
        }

        /**
         * Start an audit run.
         */
        function startRun() {
            var $button = $root.find('[data-role="run"]');

            $button.prop('disabled', true);
            setStatus($t('Starting the run…'), 'info');

            $.ajax({
                url: config.urls.start,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: config.formKey,
                    store: storeId(),
                    refresh: $root.find('[data-role="refresh"]').is(':checked') ? 1 : 0
                }
            }).done(function (response) {
                if (!response.success) {
                    setStatus(response.message || $t('The run could not be started.'), 'error');

                    return;
                }

                pollStartedAt = Date.now();
                poll(response.id);
            }).fail(function () {
                setStatus($t('Could not reach the server.'), 'error');
            }).always(function () {
                $button.prop('disabled', false);
            });
        }

        /**
         * Load the recent runs table.
         */
        function loadHistory() {
            $.ajax({
                url: config.urls.history,
                type: 'GET',
                dataType: 'json',
                data: { store: storeId() }
            }).done(function (response) {
                if (response.success) {
                    renderHistory(response.history);
                }
            });
        }

        /**
         * Load the action plan for the latest stored run.
         */
        function loadPlan() {
            $.ajax({
                url: config.urls.plan,
                type: 'GET',
                dataType: 'json',
                data: { store: storeId() }
            }).done(function (response) {
                if (!response.success) {
                    setStatus(response.message || $t('The action plan could not be built.'), 'error');

                    return;
                }

                if (!response.plan || !response.plan.length) {
                    setStatus(response.message || $t('Run an audit first.'), 'info');

                    return;
                }

                renderPlan(response.plan);
            });
        }

        /**
         * Send one test query.
         */
        function runTest() {
            var $output = $root.find('[data-role="test-output"]'),
                $button = $root.find('[data-role="test-run"]');

            $button.prop('disabled', true);
            $output.text($t('Waiting for the provider…')).prop('hidden', false);

            $.ajax({
                url: config.urls.test,
                type: 'POST',
                dataType: 'json',
                data: {
                    form_key: config.formKey,
                    store: storeId(),
                    provider: $root.find('[data-role="test-provider"]').val(),
                    prompt_key: $root.find('[data-role="test-prompt"]').val()
                }
            }).done(function (response) {
                if (!response.success) {
                    $output.text(response.message || response.error || $t('The request failed.'));

                    return;
                }

                $output.text(
                    response.provider_label + '\n\n' +
                    $t('Prompt: ') + response.prompt + '\n\n' +
                    response.raw_response + '\n\n' +
                    $t('Score: ') + response.score + '/100'
                );
            }).fail(function () {
                $output.text($t('Could not reach the server.'));
            }).always(function () {
                $button.prop('disabled', false);
            });
        }

        /**
         * Fill the test selects from the injected configuration.
         */
        function fillTestControls() {
            var $providers = $root.find('[data-role="test-provider"]').empty(),
                $prompts = $root.find('[data-role="test-prompt"]').empty();

            Object.keys(config.providers || {}).forEach(function (id) {
                $providers.append($('<option/>').attr('value', id).text(config.providers[id]));
            });

            (config.prompts || []).forEach(function (key) {
                $prompts.append($('<option/>').attr('value', key).text(key));
            });
        }

        /**
         * Point the export link at the selected store view.
         */
        function updateExportLink() {
            $root.find('[data-role="export"]').attr(
                'href',
                config.urls.export + 'store/' + encodeURIComponent(storeId()) + '/'
            );
        }

        $root.find('[data-role="run"]').on('click', startRun);
        $root.find('[data-role="load-plan"]').on('click', loadPlan);
        $root.find('[data-role="test-run"]').on('click', runTest);
        $root.find('[data-role="store-select"]').on('change', function () {
            updateExportLink();
            loadHistory();
        });

        fillTestControls();
        updateExportLink();
        loadHistory();
    };
});
