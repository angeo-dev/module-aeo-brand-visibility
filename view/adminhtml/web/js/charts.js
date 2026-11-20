/**
 * Copyright © Angeo (angeo.dev). All rights reserved.
 * See LICENSE for license details.
 *
 * Charts for brand visibility (4.2.0).
 *
 * Every chart is built with the DOM API: text goes in with .text() or
 * textContent, never as HTML, because provider answers and competitor names
 * come from outside the store. No inline <style>, no inline script: the
 * page keeps working under a strict content security policy.
 *
 * Every chart says what its numbers are a share of, and shows the margin of
 * error where one exists. A difference inside the margin is noise.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    var SVG = 'http://www.w3.org/2000/svg';

    function svg(name, attrs) {
        var node = document.createElementNS(SVG, name);
        Object.keys(attrs || {}).forEach(function (key) {
            node.setAttribute(key, String(attrs[key]));
        });
        return node;
    }

    function clamp(value) {
        var n = Number(value);
        return isFinite(n) ? Math.max(0, Math.min(100, n)) : 0;
    }

    function round1(value) {
        return Math.round(Number(value) * 10) / 10;
    }

    /**
     * How many answers named the brand and each competitor. The denominator
     * is the number of answers that came back. Same arithmetic as
     * Service\RunPayloadBuilder::namedCounts().
     */
    function namedCounts(results) {
        var answers = 0, brand = 0, competitors = {};

        (results || []).forEach(function (cell) {
            if (!cell || !cell.success) {
                return;
            }
            var samples = Math.max(0, Number(cell.samples) || 0),
                hits = (cell.meta && cell.meta.competitor_hits) || {};

            answers += samples;
            brand += samples * (Number((cell.signal_rates || {}).mentioned) || 0) / 100;
            Object.keys(hits).forEach(function (name) {
                competitors[name] = (competitors[name] || 0) + (Number(hits[name]) || 0);
            });
        });

        return {
            answers: answers,
            brand: Math.round(brand),
            competitors: Object.keys(competitors)
                .map(function (name) { return {name: name, count: competitors[name]}; })
                .sort(function (a, b) { return b.count - a.count || a.name.localeCompare(b.name); })
        };
    }

    /** Score ring with the ± margin drawn as a lighter band around the arc end. */
    function gauge(score, margin, grade) {
        var r = 50, c = 2 * Math.PI * r,
            value = clamp(score),
            lo = clamp(value - (Number(margin) || 0)),
            hi = clamp(value + (Number(margin) || 0)),
            root = svg('svg', {viewBox: '0 0 120 120', width: 132, height: 132, role: 'img', 'class': 'angeo-bv-gauge'}),
            title = svg('title', {});

        title.textContent = $t('Visibility score %1 of 100').replace('%1', String(value)) +
            (margin ? ' ±' + round1(margin) : '');
        root.appendChild(title);
        root.appendChild(svg('circle', {cx: 60, cy: 60, r: r, 'class': 'angeo-bv-gauge-track'}));
        if (hi > lo) {
            root.appendChild(svg('circle', {
                cx: 60, cy: 60, r: r, 'class': 'angeo-bv-gauge-band',
                'stroke-dasharray': (c * (hi - lo) / 100) + ' ' + c,
                'stroke-dashoffset': String(-c * lo / 100),
                transform: 'rotate(-90 60 60)'
            }));
        }
        root.appendChild(svg('circle', {
            cx: 60, cy: 60, r: r, 'class': 'angeo-bv-gauge-arc angeo-bv-gauge-' + String(grade || 'f').toLowerCase(),
            'stroke-dasharray': (c * value / 100) + ' ' + c,
            transform: 'rotate(-90 60 60)'
        }));
        var num = svg('text', {x: 60, y: 62, 'class': 'angeo-bv-gauge-num'});
        num.textContent = String(value);
        root.appendChild(num);
        var sub = svg('text', {x: 60, y: 82, 'class': 'angeo-bv-gauge-sub'});
        sub.textContent = margin ? '±' + round1(margin) : '/100';
        root.appendChild(sub);

        return root;
    }

    /**
     * The answer funnel: of all answers, how many mentioned the brand, how
     * many recommended it, cited its site, and named it first.
     */
    function funnel(rates, answers) {
        var $wrap = $('<div/>').addClass('angeo-bv-funnel'),
            steps = [
                ['mentioned', $t('Mentioned'), $t('The answer names the brand.')],
                ['recommended', $t('Recommended'), $t('Suggested, not only listed.')],
                ['url_cited', $t('Site cited'), $t('Your domain is given as a source.')],
                ['first_result', $t('Named first'), $t('Named before every competitor.')]
            ];

        steps.forEach(function (step) {
            var value = clamp((rates || {})[step[0]]),
                $row = $('<div/>').addClass('angeo-bv-funnel-row');

            $row.append($('<span/>').addClass('angeo-bv-funnel-label').text(step[1]));
            $row.append(
                $('<span/>').addClass('angeo-bv-track').append(
                    $('<span/>').addClass('angeo-bv-fill angeo-bv-fill-' + step[0]).css('width', value + '%')
                )
            );
            $row.append($('<span/>').addClass('angeo-bv-funnel-value').text(Math.round(value) + '%'));
            $row.append($('<span/>').addClass('angeo-bv-funnel-note').text(step[2]));
            $wrap.append($row);
        });

        var mentioned = clamp((rates || {}).mentioned),
            cited = clamp((rates || {}).url_cited),
            note = answers ? $t('Share of %1 answers.').replace('%1', String(answers)) + ' ' : '';

        if (mentioned >= 20 && cited < mentioned / 2) {
            note += $t('Assistants name the brand much more often than they cite its site: they know you, but do not use your pages as a source. Work on citable content and structured data, not on awareness.');
        }
        if (note) {
            $wrap.append($('<p/>').addClass('angeo-bv-note').text(note));
        }

        return $wrap;
    }

    /**
     * One row per assistant: a dot per prompt with its ± margin, and the
     * assistant's overall score as a bar. Overlapping whiskers mean the
     * difference between two assistants may be noise.
     */
    function providers(results, scoresByProvider) {
        var byProvider = {}, $wrap = $('<div/>').addClass('angeo-bv-providers');

        (results || []).forEach(function (cell) {
            var id = cell.provider_label || cell.provider_id || '?';
            (byProvider[id] = byProvider[id] || {id: cell.provider_id, cells: []}).cells.push(cell);
        });

        Object.keys(byProvider).sort().forEach(function (label) {
            var group = byProvider[label],
                overall = (scoresByProvider || {})[group.id],
                $row = $('<div/>').addClass('angeo-bv-prov-row'),
                $track = $('<span/>').addClass('angeo-bv-prov-track');

            if (overall !== null && overall !== undefined) {
                $track.append($('<span/>').addClass('angeo-bv-prov-bar').css('width', clamp(overall) + '%'));
            }
            group.cells.forEach(function (cell) {
                if (!cell.success) {
                    return;
                }
                var score = clamp(cell.score), m = Number(cell.score_margin) || 0,
                    tip = (cell.prompt_key || '') + ': ' + score + (m ? ' ±' + round1(m) : '');

                if (m > 0) {
                    $track.append(
                        $('<span/>').addClass('angeo-bv-prov-whisker')
                            .css({left: clamp(score - m) + '%', width: (clamp(score + m) - clamp(score - m)) + '%'})
                            .attr('title', tip)
                    );
                }
                $track.append($('<span/>').addClass('angeo-bv-prov-dot').css('left', score + '%').attr('title', tip));
            });

            $row.append($('<span/>').addClass('angeo-bv-prov-label').text(label));
            $row.append($track);
            $row.append($('<span/>').addClass('angeo-bv-prov-value').text(
                overall === null || overall === undefined ? $t('no data') : String(overall)
            ));
            $wrap.append($row);
        });

        $wrap.append($('<p/>').addClass('angeo-bv-note').text(
            $t('Bar: the assistant\'s score. Dots: one per prompt, with its ± margin. When the lines of two assistants overlap, the difference may be noise.')
        ));

        return $wrap;
    }

    /** Answers that named the brand vs each competitor. */
    function competitors(results, brandName, limit) {
        var counts = namedCounts(results),
            rows = [{name: brandName || $t('Your brand'), count: counts.brand, brand: true}]
                .concat(counts.competitors.slice(0, limit || 8)),
            max = Math.max.apply(null, rows.map(function (r) { return r.count; }).concat([1])),
            $wrap = $('<div/>').addClass('angeo-bv-comp');

        if (!counts.answers) {
            return $wrap.append($('<p/>').addClass('angeo-bv-note').text($t('No answers to count.')));
        }

        rows.forEach(function (row) {
            var $row = $('<div/>').addClass('angeo-bv-comp-row' + (row.brand ? ' angeo-bv-comp-brand' : ''));
            $row.append($('<span/>').addClass('angeo-bv-comp-label').text(row.name));
            $row.append(
                $('<span/>').addClass('angeo-bv-track').append(
                    $('<span/>').addClass('angeo-bv-fill').css('width', (100 * row.count / max) + '%')
                )
            );
            $row.append($('<span/>').addClass('angeo-bv-comp-value').text(
                $t('%1 of %2').replace('%1', String(row.count)).replace('%2', String(counts.answers))
            ));
            $wrap.append($row);
        });
        $wrap.append($('<p/>').addClass('angeo-bv-note').text(
            $t('Answers that name each business, out of all answers in this run. Competitors are the names you track in the settings.')
        ));

        return $wrap;
    }

    /** Score over recent runs, oldest left, with the ± margin as a band. */
    function trend(rows) {
        var points = (rows || []).slice().reverse().filter(function (r) {
                return r && r.overall_score !== undefined && r.overall_score !== null;
            }),
            w = 640, h = 180, padL = 30, padR = 10, padT = 10, padB = 26,
            root = svg('svg', {viewBox: '0 0 ' + w + ' ' + h, 'class': 'angeo-bv-trend', role: 'img'}),
            title = svg('title', {}),
            x, y, line = [], upper = [], lower = [];

        title.textContent = $t('Visibility score over the last %1 runs').replace('%1', String(points.length));
        root.appendChild(title);
        if (points.length < 2) {
            return null;
        }

        x = function (i) { return padL + (w - padL - padR) * i / (points.length - 1); };
        y = function (v) { return padT + (h - padT - padB) * (1 - clamp(v) / 100); };

        [0, 25, 50, 75, 100].forEach(function (v) {
            root.appendChild(svg('line', {x1: padL, x2: w - padR, y1: y(v), y2: y(v), 'class': 'angeo-bv-trend-grid'}));
            var label = svg('text', {x: padL - 6, y: y(v) + 4, 'class': 'angeo-bv-trend-tick', 'text-anchor': 'end'});
            label.textContent = String(v);
            root.appendChild(label);
        });

        points.forEach(function (p, i) {
            var m = Number(p.score_margin) || 0;
            line.push(x(i) + ',' + y(p.overall_score));
            upper.push(x(i) + ',' + y(Number(p.overall_score) + m));
            lower.unshift(x(i) + ',' + y(Number(p.overall_score) - m));
        });
        root.appendChild(svg('polygon', {points: upper.concat(lower).join(' '), 'class': 'angeo-bv-trend-band'}));
        root.appendChild(svg('polyline', {points: line.join(' '), 'class': 'angeo-bv-trend-line'}));

        points.forEach(function (p, i) {
            var dot = svg('circle', {cx: x(i), cy: y(p.overall_score), r: 3.5, 'class': 'angeo-bv-trend-dot'}),
                tip = svg('title', {});
            tip.textContent = String(p.created_at || '') + ': ' + p.overall_score +
                (p.score_margin ? ' ±' + round1(p.score_margin) : '');
            dot.appendChild(tip);
            root.appendChild(dot);
            if (i === 0 || i === points.length - 1) {
                var label = svg('text', {x: x(i), y: h - 6, 'class': 'angeo-bv-trend-tick',
                    'text-anchor': i === 0 ? 'start' : 'end'});
                label.textContent = String(p.created_at || '').substr(0, 10);
                root.appendChild(label);
            }
        });

        return root;
    }

    /** Fill a container with every chart for one finished run. */
    function renderRun($target, data, brandName) {
        var counts = namedCounts(data.results);

        $target.empty();
        $target.append(
            $('<section/>').addClass('angeo-bv-chart angeo-bv-chart-score').append(
                $('<h3/>').text($t('Visibility score')),
                gauge(data.overall_score, data.score_margin, data.grade),
                $('<p/>').addClass('angeo-bv-note').text(
                    $t('Weighted signals per answer, averaged over %1 answers. ± is the 95% margin: a change smaller than that is noise.')
                        .replace('%1', String(counts.answers))
                )
            ),
            $('<section/>').addClass('angeo-bv-chart').append(
                $('<h3/>').text($t('From mention to recommendation')),
                funnel(data.signal_rates, counts.answers)
            ),
            $('<section/>').addClass('angeo-bv-chart').append(
                $('<h3/>').text($t('By assistant')),
                providers(data.results, data.scores_by_provider)
            ),
            $('<section/>').addClass('angeo-bv-chart').append(
                $('<h3/>').text($t('Named next to competitors')),
                competitors(data.results, brandName || data.brand_name)
            )
        );
    }

    return {
        namedCounts: namedCounts,
        gauge: gauge,
        funnel: funnel,
        providers: providers,
        competitors: competitors,
        trend: trend,
        renderRun: renderRun
    };
});
