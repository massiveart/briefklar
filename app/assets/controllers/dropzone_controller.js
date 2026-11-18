import { Controller } from '@hotwired/stimulus';

/*
 * Drag-and-drop around the native file input of the upload form.
 *
 * Progressive enhancement: the real <input type="file"> stays in the form and is only hidden
 * visually once this controller connects, so click, keyboard and screen readers keep using the
 * native control, and without JavaScript the input is simply visible. Type and size are checked
 * here for a quick message; the server's validation stays the source of truth.
 */
export default class extends Controller {
    static targets = ['input', 'name', 'error'];
    static values = { maxBytes: Number, types: Array, messages: Object };

    connect() {
        // Kept for disconnect(), when the target may no longer be reachable.
        this.input = this.inputTarget;
        this.input.classList.add('visually-hidden');
        // A letter dropped next to the zone would otherwise make the browser open it and leave the page.
        this.preventNavigation = (event) => {
            if (event.dataTransfer && Array.from(event.dataTransfer.types).includes('Files')) {
                event.preventDefault();
            }
        };
        window.addEventListener('dragover', this.preventNavigation);
        window.addEventListener('drop', this.preventNavigation);
        // Back button: the page may come back from the cache with a stale file name.
        this.sync = this.sync.bind(this);
        window.addEventListener('pageshow', this.sync);
        this.sync();
    }

    disconnect() {
        window.removeEventListener('dragover', this.preventNavigation);
        window.removeEventListener('drop', this.preventNavigation);
        window.removeEventListener('pageshow', this.sync);
        this.input.classList.remove('visually-hidden');
    }

    over(event) {
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'copy';
        }
        this.element.classList.add('is-over');
    }

    leave(event) {
        // dragleave also fires when moving onto a child element.
        if (!this.element.contains(event.relatedTarget)) {
            this.element.classList.remove('is-over');
        }
    }

    drop(event) {
        event.preventDefault();
        this.element.classList.remove('is-over');
        const files = event.dataTransfer ? event.dataTransfer.files : null;
        if (!files || files.length === 0) {
            return;
        }
        if (files.length > 1) {
            this.reject(this.messagesValue.one_file);
            return;
        }
        if (!this.accept(files[0])) {
            return;
        }
        this.inputTarget.files = files;
        this.show(files[0]);
    }

    pick() {
        const file = this.inputTarget.files[0];
        if (!file) {
            this.sync();
            return;
        }
        if (this.accept(file)) {
            this.show(file);
        }
    }

    accept(file) {
        if (this.typesValue.length > 0 && !this.typesValue.includes(file.type)) {
            this.reject(this.messagesValue.wrong_type);
            return false;
        }
        if (this.maxBytesValue > 0 && file.size > this.maxBytesValue) {
            this.reject(this.messagesValue.too_big);
            return false;
        }
        return true;
    }

    reject(message) {
        this.inputTarget.value = '';
        this.nameTarget.hidden = true;
        this.element.classList.remove('has-file');
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }

    show(file) {
        this.errorTarget.hidden = true;
        this.nameTarget.textContent = `${this.messagesValue.chosen}: ${file.name} (${this.size(file.size)})`;
        this.nameTarget.title = file.name;
        this.nameTarget.hidden = false;
        this.element.classList.add('has-file');
    }

    sync() {
        const file = this.inputTarget.files && this.inputTarget.files[0];
        if (file) {
            this.show(file);
            return;
        }
        this.nameTarget.hidden = true;
        this.errorTarget.hidden = true;
        this.element.classList.remove('has-file', 'is-over');
    }

    size(bytes) {
        const locale = document.documentElement.lang || undefined;
        if (bytes >= 1000000) {
            return `${(bytes / 1000000).toLocaleString(locale, { maximumFractionDigits: 1 })} MB`;
        }
        return `${Math.max(1, Math.round(bytes / 1000)).toLocaleString(locale)} KB`;
    }
}
