import { Controller } from '@hotwired/stimulus';

/*
 * Shows that the letter is being explained while the (synchronous) upload request runs:
 * reading, redacting and the model call take several seconds.
 *
 * The step list is an indication of what happens, not real progress: the request runs in one
 * piece, so the highlighted step simply moves on every few seconds and stays on the last one.
 *
 * Progressive enhancement: without JavaScript the form submits as usual.
 * Prevents a second submit, which would send the letter to the provider twice.
 */
const STEP_SECONDS = 8;

export default class extends Controller {
    static targets = ['submit', 'status', 'step'];
    static values = { busyLabel: String };

    connect() {
        this.idleLabel = this.submitTarget.textContent;
        // Coming back with the browser's back button restores the page from the cache, still busy.
        this.reset = this.reset.bind(this);
        window.addEventListener('pageshow', this.reset);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.reset);
        this.stopSteps();
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
        this.startSteps();
    }

    reset() {
        this.element.removeAttribute('aria-busy');
        this.submitTarget.disabled = false;
        this.submitTarget.textContent = this.idleLabel;
        this.statusTarget.hidden = true;
        this.stopSteps();
    }

    startSteps() {
        this.stopSteps();
        let current = 0;
        this.markStep(current);
        this.timer = window.setInterval(() => {
            current = Math.min(current + 1, this.stepTargets.length - 1);
            this.markStep(current);
        }, STEP_SECONDS * 1000);
    }

    stopSteps() {
        window.clearInterval(this.timer);
        this.timer = null;
        this.stepTargets.forEach((step) => step.classList.remove('is-current', 'is-done'));
    }

    markStep(current) {
        this.stepTargets.forEach((step, index) => {
            step.classList.toggle('is-done', index < current);
            step.classList.toggle('is-current', index === current);
        });
    }
}
