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
 * Report builder filter management
 *
 * @module      core_reportbuilder/filters
 * @package     core_reportbuilder
 * @copyright   2021 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {dispatchEvent} from 'core/event_dispatcher';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {get_string as getString} from 'core/str';
import {add as addToast} from 'core/toast';
import DynamicForm from 'core_form/dynamicform';
import * as reportEvents from 'core_reportbuilder/local/events';
import * as reportSelectors from 'core_reportbuilder/local/selectors';
import {reset as resetFilters} from 'core_reportbuilder/local/repository/filters';

/**
 * Initialise module
 *
 * @param {Number} reportId
 */
export const init = reportId => {
    const reportElement = document.querySelector(reportSelectors.forSystemReport(reportId));
    const filterFormContainer = reportElement.querySelector(reportSelectors.regions.filtersForm);
    const filterForm = new DynamicForm(filterFormContainer, '\\core_reportbuilder\\form\\filter');

    // Submit report filters.
    filterForm.addEventListener(filterForm.events.FORM_SUBMITTED, event => {
        event.preventDefault();

        // After the form has been submitted, we should trigger report table reload.
        dispatchEvent(reportEvents.tableReload, {}, reportElement);

        getString('filtersapplied', 'core_reportbuilder')
            .then(addToast)
            .catch(Notification.exception);
    });

    // Reset report filters.
    filterForm.addEventListener(filterForm.events.NOSUBMIT_BUTTON_PRESSED, event => {
        event.preventDefault();

        const pendingPromise = new Pending('core_reportbuilder/filters:reset');

        resetFilters(reportId)
            .then(() => getString('filtersreset', 'core_reportbuilder'))
            .then(addToast)
            .then(() => {
                pendingPromise.resolve();
                window.location.reload();
                return;
            })
            .catch(Notification.exception);
    });

    // Modify "region-main" overflow for big filter forms.
    document.querySelector('#region-main').style.overflowX = "visible";
};
