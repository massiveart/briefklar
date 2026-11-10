import { Controller } from '@hotwired/stimulus';

/*
 * Shows that the letter is being explained while the (synchronous) upload request runs:
 * reading, redacting and the model call take several seconds.
 *
 * Progressive enhancement: without JavaScript the form submits as usual.
 * Prevents a second submit, which would send the letter to the provider twice.
 */
export default class extends Controller {
    static targets = ['submit', 'status'];
    static values = { busyLabel: String };

    connect() {
        this.idleLabel = this.submitTarget.textContent;
        // Coming back with the browser's back button restores the page from the cache, still busy.
        this.reset = this.reset.bind(this);
        window.addEventListener('pageshow', this.reset);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.reset);
    }

    start(event) {
        if (this.element.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }

        this.element.setAttribute('aria-busy', 'true');
        this.submitTarget.disabled = true;
        this.submitTarget.textContent = this.busyLabelValue;
        this.statusTarget.hidden = false;
    }

    reset() {
        this.element.removeAttribute('aria-busy');
        this.submitTarget.disabled = false;
        this.submitTarget.textContent = this.idleLabel;
        this.statusTarget.hidden = true;
    }
}
