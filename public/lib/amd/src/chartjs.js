// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Chart.js loader.
 *
 * Chart.js draws onto a canvas, which the page styles cannot reach, and it has no colour mode support of its own. The
 * colours it uses by default for text, lines and pie segment borders are therefore read from the page, and kept in step
 * with the colour mode set by the data-bs-theme attribute of the html tag.
 *
 * @copyright  2016 Frédéric Massart - FMCorz.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/chartjs-lazy'], function(ChartJS) {

    const FALLBACK_COLOURS = {
        color: ChartJS.defaults.color,
        borderColor: ChartJS.defaults.borderColor,
        arcBorderColor: ChartJS.defaults.elements.arc.borderColor,
    };

    // The scale options which Chart.js routes to its default colours, mapped to the default they come from.
    const SCALE_COLOUR_ROUTES = {
        ticks: 'color',
        title: 'color',
        pointLabels: 'color',
        grid: 'borderColor',
        border: 'borderColor',
        angleLines: 'borderColor',
    };

    /**
     * Set the default Chart.js colours from those of the page.
     *
     * Falls back to the Chart.js colours when the theme does not provide the Bootstrap custom properties.
     */
    const applyColours = () => {
        const style = window.getComputedStyle(document.documentElement);
        const getColour = (property, fallback) => style.getPropertyValue(property).trim() || fallback;

        ChartJS.defaults.color = getColour('--bs-secondary-color', FALLBACK_COLOURS.color);
        ChartJS.defaults.borderColor = getColour('--bs-border-color-translucent', FALLBACK_COLOURS.borderColor);
        ChartJS.defaults.elements.arc.borderColor = getColour('--bs-body-bg', FALLBACK_COLOURS.arcBorderColor);
    };

    /**
     * Redraw the existing charts after the default colours have changed.
     *
     * Chart.js copies the default colours into the scale options of a chart when it is created, so changing the
     * defaults does not reach them. Those still holding the previous default are moved to the new one, leaving any
     * colour which was set explicitly alone.
     *
     * @param {Object} previous The default colours before they changed, keyed by their Chart.js name.
     */
    const refreshCharts = (previous) => {
        Object.values(ChartJS.instances).forEach((chart) => {
            Object.values(chart.config.options.scales || {}).forEach((scale) => {
                Object.entries(SCALE_COLOUR_ROUTES).forEach(([option, colour]) => {
                    if (scale[option] && scale[option].color === previous[colour]) {
                        scale[option].color = ChartJS.defaults[colour];
                    }
                });
            });
            chart.update('none');
        });
    };

    applyColours();

    new MutationObserver(() => {
        const previous = {
            color: ChartJS.defaults.color,
            borderColor: ChartJS.defaults.borderColor,
        };
        applyColours();
        refreshCharts(previous);
    }).observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});

    return ChartJS;
});
