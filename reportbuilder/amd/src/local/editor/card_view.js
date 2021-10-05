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
 * Report builder card view editor
 *
 * @module      core_reportbuilder/local/editor/card_view
 * @copyright   2021 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

"use strict";

import DynamicForm from 'core_form/dynamicform';
import {add as addToast} from 'core/toast';
import {get_string as getString} from "core/str";
import {subscribe as subscribe} from 'core/pubsub';
import Notification from 'core/notification';
import * as reportEvents from 'core_reportbuilder/local/events';
import * as reportSelectors from 'core_reportbuilder/local/selectors';

/**
 * Initialise module
 *
 * @param {Element} reportElement
 */
export const init = (reportElement) => {
    const cardViewFormContainer = document.querySelector(reportSelectors.regions.settingsCardView);
    const cardViewForm = new DynamicForm(cardViewFormContainer, '\\core_reportbuilder\\form\\card_view');

    cardViewForm.addEventListener(cardViewForm.events.FORM_SUBMITTED, (event) => {
        event.preventDefault();

        getString('cardviewsettingssaved', 'core_reportbuilder')
            .then(addToast)
            .catch(Notification.exception);
    });

    // Update form each time a column is added or removed to the custom report.
    subscribe(reportEvents.publish.reportColumnsUpdated, () => {
        cardViewForm.load({reportid: reportElement.getAttribute('data-report-id')});
    });
};