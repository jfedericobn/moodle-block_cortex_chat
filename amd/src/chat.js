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
 * Course-scoped Cortex RAG chat UI controller.
 *
 * @module     block_cortex_chat/chat
 * @copyright  2026 Cortex integration
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {getString} from 'core/str';
import Policy from 'core_ai/policy';

const SELECTORS = {
    ROOT: '[data-region="cortex-chat"]',
    LOG: '[data-region="cortex-chat-log"]',
    FORM: '[data-region="cortex-chat-form"]',
    INPUT: '[data-region="cortex-chat-input"]',
    SEND: '[data-action="cortex-chat-send"]',
    CHARCOUNT: '[data-region="cortex-chat-charcount"]',
    GROUNDING: '[data-region="cortex-chat-grounding"]',
    SOURCESTOGGLE: '[data-region="cortex-chat-sources-toggle"]',
};

/** localStorage key for the user's "show sources" preference (per browser). */
const SHOW_SOURCES_STORAGE_KEY = 'block_cortex_chat_showsources';

/**
 * Controller for a single chat block instance.
 */
class CortexChat {
    /**
     * @param {HTMLElement} root The block root element.
     * @param {Object} config The bootstrap config.
     */
    constructor(root, config) {
        this.root = root;
        this.contextid = config.contextid;
        this.maxlength = config.maxlength;
        this.busy = false;

        this.log = root.querySelector(SELECTORS.LOG);
        this.form = root.querySelector(SELECTORS.FORM);
        this.input = root.querySelector(SELECTORS.INPUT);
        this.send = root.querySelector(SELECTORS.SEND);
        this.charcount = root.querySelector(SELECTORS.CHARCOUNT);
        this.grounding = root.querySelector(SELECTORS.GROUNDING);
        this.sourcestoggle = root.querySelector(SELECTORS.SOURCESTOGGLE);

        this.registerListeners();
        this.updateCharCount();
        this.initSourcesToggle();
    }

    /**
     * Restore the "show sources" preference from localStorage and apply it.
     *
     * This is a per-browser display preference only (no server round-trip and
     * no data sent anywhere); it only shows/hides the sources list already
     * present in each rendered answer to keep responses shorter to read.
     */
    initSourcesToggle() {
        if (!this.sourcestoggle) {
            return;
        }
        let show = true;
        try {
            const stored = window.localStorage.getItem(SHOW_SOURCES_STORAGE_KEY);
            show = stored === null ? true : stored === '1';
        } catch (error) {
            // localStorage unavailable (e.g. private browsing); keep the default.
            show = true;
        }
        this.sourcestoggle.checked = show;
        this.applySourcesVisibility(show);

        this.sourcestoggle.addEventListener('change', () => {
            const checked = this.sourcestoggle.checked;
            this.applySourcesVisibility(checked);
            try {
                window.localStorage.setItem(SHOW_SOURCES_STORAGE_KEY, checked ? '1' : '0');
            } catch (error) {
                // Ignore storage failures; the toggle still works for this page view.
                window.console.error(error);
            }
        });
    }

    /**
     * Show or hide the sources list under each rendered answer.
     *
     * @param {Boolean} show
     */
    applySourcesVisibility(show) {
        this.root.classList.toggle('block-cortex-chat-hide-sources', !show);
    }

    /**
     * Wire up UI events.
     */
    registerListeners() {
        if (this.form) {
            this.form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.onSubmit();
            });
        }
        if (this.input) {
            this.input.addEventListener('input', () => this.updateCharCount());
            // Enter sends, Shift+Enter inserts a newline.
            this.input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.onSubmit();
                }
            });
        }
    }

    /**
     * Update the live character counter.
     */
    updateCharCount() {
        if (!this.charcount || !this.input) {
            return;
        }
        this.charcount.textContent = `${this.input.value.length}/${this.maxlength}`;
    }

    /**
     * Handle a submit: policy gate, then send.
     */
    async onSubmit() {
        if (this.busy) {
            return;
        }
        const message = (this.input.value || '').trim();
        if (message === '') {
            return;
        }

        // Enforce Moodle AI policy acceptance before the first request.
        try {
            const accepted = await Policy.getPolicyStatus(M.cfg.userId);
            if (!accepted) {
                await this.showPolicy();
                return;
            }
        } catch (error) {
            window.console.error(error);
        }

        this.sendMessage(message);
    }

    /**
     * Render the core AI policy block and wire accept/decline.
     */
    async showPolicy() {
        try {
            const html = await Templates.render('core_ai/policyblock', {});
            const wrapper = document.createElement('div');
            wrapper.className = 'block-cortex-chat-policy';
            wrapper.innerHTML = html;
            this.log.appendChild(wrapper);
            this.scrollToBottom();

            const accept = wrapper.querySelector('[data-action="accept"]');
            const decline = wrapper.querySelector('[data-action="decline"]');
            if (accept) {
                accept.addEventListener('click', (e) => {
                    e.preventDefault();
                    Policy.acceptPolicy().then(() => {
                        wrapper.remove();
                        this.input.focus();
                        return;
                    }).catch(Notification.exception);
                });
            }
            if (decline) {
                decline.addEventListener('click', (e) => {
                    e.preventDefault();
                    wrapper.remove();
                });
            }
        } catch (error) {
            Notification.exception(error);
        }
    }

    /**
     * Send a message to the backend and render the response.
     *
     * @param {String} message
     */
    async sendMessage(message) {
        this.setBusy(true);
        await this.appendMessage({
            isuser: true,
            rolelabel: await getString('youlabel', 'block_cortex_chat'),
            message: message,
            hassources: false,
            sources: [],
        });
        this.input.value = '';
        this.updateCharCount();

        const pending = await this.appendPending();

        try {
            const response = await Ajax.call([{
                methodname: 'block_cortex_chat_send_message',
                args: {
                    contextid: this.contextid,
                    message: message,
                },
            }])[0];
            await this.renderResponse(response, pending);
        } catch (error) {
            window.console.error(error);
            await this.replaceWithError(pending, await getString('error_generic', 'block_cortex_chat'));
        } finally {
            this.setBusy(false);
            this.input.focus();
        }
    }

    /**
     * Turn a server response into a rendered assistant message.
     *
     * @param {Object} response
     * @param {HTMLElement} pending Placeholder node to replace.
     */
    async renderResponse(response, pending) {
        // Staff diagnostics.
        if (this.grounding && response.groundingstate) {
            this.grounding.textContent = response.groundingstate;
        }

        if (response.status === 'answered' || response.status === 'declined') {
            const node = await this.buildMessage({
                isuser: false,
                rolelabel: await getString('assistantlabel', 'block_cortex_chat'),
                message: response.answer,
                hassources: Array.isArray(response.sources) && response.sources.length > 0,
                sources: response.sources || [],
            });
            pending.replaceWith(node);
            this.scrollToBottom();
            return;
        }

        // Unavailable / error: map the non-sensitive code to a friendly string.
        const text = await this.messageForError(response.errorcode);
        await this.replaceWithError(pending, text);
    }

    /**
     * Map a non-sensitive error/reason code to a localised message.
     *
     * @param {String} code
     * @return {Promise<String>}
     */
    async messageForError(code) {
        const known = [
            'serviceunavailable', 'aifailed', 'ratelimited',
            'messagetoolong', 'emptymessage', 'policynotaccepted',
        ];
        if (known.indexOf(code) !== -1) {
            return getString('error_' + code, 'block_cortex_chat');
        }
        return getString('error_generic', 'block_cortex_chat');
    }

    /**
     * Append a rendered message to the log.
     *
     * @param {Object} context Message template context.
     * @return {Promise<HTMLElement>}
     */
    async appendMessage(context) {
        const node = await this.buildMessage(context);
        this.log.appendChild(node);
        this.scrollToBottom();
        return node;
    }

    /**
     * Build a message DOM node from the mustache template.
     *
     * @param {Object} context
     * @return {Promise<HTMLElement>}
     */
    async buildMessage(context) {
        const {html} = await Templates.renderForPromise('block_cortex_chat/message', context);
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        return wrapper.firstElementChild;
    }

    /**
     * Append a "thinking" placeholder message.
     *
     * @return {Promise<HTMLElement>}
     */
    async appendPending() {
        const node = document.createElement('div');
        node.className = 'block-cortex-chat-message block-cortex-chat-message-assistant block-cortex-chat-pending';
        node.setAttribute('aria-busy', 'true');
        const label = document.createElement('div');
        label.className = 'block-cortex-chat-role small fw-bold';
        label.textContent = await getString('assistantlabel', 'block_cortex_chat');
        const text = document.createElement('div');
        text.className = 'block-cortex-chat-text text-muted';
        text.textContent = await getString('sending', 'block_cortex_chat');
        node.appendChild(label);
        node.appendChild(text);
        this.log.appendChild(node);
        this.scrollToBottom();
        return node;
    }

    /**
     * Replace a placeholder with a plain error message.
     *
     * @param {HTMLElement} pending
     * @param {String} text
     */
    async replaceWithError(pending, text) {
        const node = document.createElement('div');
        node.className = 'block-cortex-chat-message block-cortex-chat-message-assistant block-cortex-chat-error';
        node.setAttribute('role', 'alert');
        const label = document.createElement('div');
        label.className = 'block-cortex-chat-role small fw-bold';
        label.textContent = await getString('assistantlabel', 'block_cortex_chat');
        const body = document.createElement('div');
        body.className = 'block-cortex-chat-text text-danger';
        body.textContent = text;
        node.appendChild(label);
        node.appendChild(body);
        pending.replaceWith(node);
        this.scrollToBottom();
    }

    /**
     * Toggle the busy/disabled state of the input controls.
     *
     * @param {Boolean} busy
     */
    setBusy(busy) {
        this.busy = busy;
        if (this.input) {
            this.input.disabled = busy;
        }
        if (this.send) {
            this.send.disabled = busy;
        }
    }

    /**
     * Scroll the conversation log to the newest message.
     */
    scrollToBottom() {
        if (this.log) {
            this.log.scrollTop = this.log.scrollHeight;
        }
    }
}

export const init = (config) => {
    const root = document.querySelector(SELECTORS.ROOT + `[data-contextid="${config.contextid}"]`);
    if (!root || root.dataset.initialised === '1') {
        return;
    }
    root.dataset.initialised = '1';
    new CortexChat(root, config);
};
