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
 * User selector adaptor for autocomplete form element.
 *
 * @module      core/form-user-selector
 * @class       form-user-selector
 * @package     core
 * @copyright   2020 Paul Holden <paulh@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since       3.9
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';

/**
 * Autocomplete transport method
 *
 * @param {String} selector Element selector
 * @param {String} query Search query
 * @param {Function} success Callback executed on success
 * @param {Function} failure Callback executed on failure
 */
const transport = (selector, query, success, failure) => {
    Ajax.call([{
        methodname: 'core_webservice_search_users',
        args: {
            query: query,
        }
    }])[0].then(async(results) => {
        const promises = results.map((user) => Templates.render('core/form-user-selector-suggestion', user));

        // Apply the rendered user suggestions to the results.
        return await Promise.all(promises).then((suggestions) => {
            results.forEach((user, index) => {
                user._label = suggestions[index];
            });

            success(results);

            return;
        });
    }).catch(failure);
};

/**
 * Autocomplete results processing method
 *
 * @param {String} selector Element selector
 * @param {Object[]} results Returned results
 * @return {Object[]}
 */
const processResults = (selector, results) => {
    let users = [];

    results.forEach((user) => {
        users.push({
            value: user.id,
            label: user._label
        });
    });

    return users;
};

export default {
    transport: transport,
    processResults: processResults
};