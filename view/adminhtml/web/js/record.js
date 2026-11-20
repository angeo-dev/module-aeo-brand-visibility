/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 *
 * Charts on the page of one stored run (history → View). The run comes from
 * the server in the component config; nothing is fetched.
 */
define(['jquery', 'Angeo_AeoBrandVisibility/js/charts'], function ($, charts) {
    'use strict';

    return function (config, element) {
        if (config && config.run) {
            charts.renderRun($(element), config.run, config.run.brand_name);
        }
    };
});
