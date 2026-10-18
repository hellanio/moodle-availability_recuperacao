/**
 * JavaScript for the editor of the restriction "Recovery round" (M1.4c, ADR-018).
 *
 * The restriction never chooses the activity: it names one of the rounds whose recovery activity is the activity being
 * edited (given by classes/frontend.php). It sets a private rule, so the core closes every eye of the tree (the
 * activity is hidden entirely from who does not meet it), and it points out, as errors, what the server refuses
 * anyway: the restriction inside a restriction set, a root not joined with "must match all" and an open eye.
 *
 * @module moodle-availability_recuperacao-form
 */
M.availability_recuperacao = M.availability_recuperacao || {};

/**
 * @class M.availability_recuperacao.form
 * @extends M.core_availability.plugin
 */
M.availability_recuperacao.form = Y.Object(M.core_availability.plugin);

/**
 * Rounds whose recovery activity is the activity being edited: objects with id and name (already formatted).
 *
 * @property rounds
 * @type Array
 */
M.availability_recuperacao.form.rounds = null;

/**
 * Initialises this plugin.
 *
 * @method initInner
 * @param {Array} rounds Rounds of the activity, objects with id and name.
 */
M.availability_recuperacao.form.initInner = function(rounds) {
    this.rounds = rounds || [];
};

/**
 * The round with the given id among those of the activity.
 *
 * @method findRound
 * @param {Number} id Round id.
 * @return {Object|null} The round, null when the activity is not the recovery activity of that round.
 */
M.availability_recuperacao.form.findRound = function(id) {
    for (var i = 0; i < this.rounds.length; i++) {
        if (this.rounds[i].id === id) {
            return this.rounds[i];
        }
    }
    return null;
};

/**
 * Builds the controls: the name of the round when the activity has only one, otherwise a list of its rounds. A round
 * saved before that does not use this activity (any more) is shown as unavailable.
 *
 * @method getNode
 * @param {Object} json Decoded JSON of the condition.
 * @return {Y.Node} Controls.
 */
M.availability_recuperacao.form.getNode = function(json) {
    var title = M.util.get_string('title', 'availability_recuperacao');
    var stored = NaN;
    if (json.creating === undefined && json.id !== undefined) {
        stored = parseInt(json.id, 10);
    }
    var known = isNaN(stored) ? null : this.findRound(stored);
    var html;
    var i;

    if (this.rounds.length === 1 && (isNaN(stored) || known)) {
        // Names have already been escaped by format_string.
        html = '<span class="pe-3">' + title + '</span> ' +
                '<strong class="availability-recuperacao-round" tabindex="-1">' + this.rounds[0].name + '</strong>' +
                '<input type="hidden" name="id" value="' + this.rounds[0].id + '">';
    } else {
        html = '<label><span class="pe-3">' + title + '</span> ' +
                '<span class="availability-recuperacao">' +
                '<select name="id" class="form-select">' +
                '<option value="choose">' + M.util.get_string('choosedots', 'moodle') + '</option>';
        for (i = 0; i < this.rounds.length; i++) {
            html += '<option value="' + this.rounds[i].id + '">' + this.rounds[i].name + '</option>';
        }
        if (!isNaN(stored) && !known) {
            html += '<option value="' + stored + '">' +
                    M.util.get_string('unavailableround', 'availability_recuperacao', stored) + '</option>';
        }
        html += '</select></span></label>';
    }
    var node = Y.Node.create('<span class="d-flex flex-wrap align-items-center">' + html + '</span>');

    var select = node.one('select[name=id]');
    if (select) {
        if (!isNaN(stored)) {
            select.set('value', '' + stored);
        }
        select.on('change', function() {
            M.core_availability.form.update();
        });
    }

    // The activity must be hidden entirely from who does not meet the restriction: the private rule closes and locks
    // every eye of the tree. Deferred so that the event bubbles to the root list (the item is not attached yet), then
    // the field is updated with the closed eyes.
    M.util.js_pending('availability_recuperacao_private');
    window.setTimeout(function() {
        node.fire('availability:privateRuleSet', {plugin: 'recuperacao'});
        if (M.core_availability.form.rootList) {
            M.core_availability.form.update();
        }
        M.util.js_complete('availability_recuperacao_private');
    }, 0);

    return node;
};

/**
 * Writes the round id, as an integer, when one is chosen.
 *
 * @method fillValue
 * @param {Object} value Value of the condition (written to).
 * @param {Y.Node} node Controls.
 */
M.availability_recuperacao.form.fillValue = function(value, node) {
    var selected = node.one('[name=id]').get('value');
    if (/^[0-9]+$/.test(selected)) {
        value.id = parseInt(selected, 10);
    }
};

/**
 * Points out what the server refuses: no round, a round that does not use this activity, the restriction inside a
 * restriction set, a root not joined with "must match all" and an open eye.
 *
 * @method fillErrors
 * @param {Array} errors Errors so far (written to).
 * @param {Y.Node} node Controls.
 */
M.availability_recuperacao.form.fillErrors = function(errors, node) {
    var value = {};
    this.fillValue(value, node);
    if (value.id === undefined) {
        errors.push('availability_recuperacao:error_selectround');
    } else if (!this.findRound(value.id)) {
        errors.push('availability_recuperacao:error_unavailableround');
    }

    var root = M.core_availability.form.rootList;
    if (!root) {
        return;
    }
    var item = null;
    for (var i = 0; i < root.children.length; i++) {
        if (root.children[i].pluginNode === node) {
            item = root.children[i];
        }
    }
    if (!item) {
        errors.push('availability_recuperacao:error_toplevel');
    }
    if (root.getValue().op !== '&') {
        errors.push('availability_recuperacao:error_rootand');
    } else if (item && item.eyeIcon && !item.eyeIcon.isHidden() && !item.eyeIcon.isDisabled()) {
        errors.push('availability_recuperacao:error_hidden');
    }
};

/**
 * Focuses the list of rounds, or the name of the only round.
 *
 * @method focusAfterAdd
 * @param {Y.Node} node Controls.
 */
M.availability_recuperacao.form.focusAfterAdd = function(node) {
    var target = node.one('select[name=id]') || node.one('.availability-recuperacao-round');
    if (target) {
        target.focus();
    }
};
