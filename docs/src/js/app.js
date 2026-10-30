import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('docsApp', () => ({
    sidebarOpen: false,
    query: '',
    lightTheme: false,

    init() {
        try {
            this.lightTheme = localStorage.getItem('storix-docs-theme') === 'light';
        } catch (_) {
            this.lightTheme = false;
        }

        this.$watch('lightTheme', (value) => {
            document.documentElement.classList.toggle('light-theme', value);
            try {
                localStorage.setItem('storix-docs-theme', value ? 'light' : 'dark');
            } catch (_) {
                // Private browsing may disable localStorage; theme still works for this session.
            }
        });

        document.documentElement.classList.toggle('light-theme', this.lightTheme);
        this.observeSections();
        this.addKeyboardShortcut();
        this.addCopyButtons();
    },

    closeSidebar() {
        this.sidebarOpen = false;
    },

    toggleTheme() {
        this.lightTheme = !this.lightTheme;
    },

    matchesText(text) {
        if (!this.query.trim()) return true;
        return text.toLowerCase().includes(this.query.trim().toLowerCase());
    },

    matchesElement(element) {
        return this.matchesText(element?.innerText || '');
    },

    clearSearch() {
        this.query = '';
        this.$nextTick(() => this.$refs.search?.focus());
    },

    observeSections() {
        const links = [...document.querySelectorAll('[data-doc-link]')];
        const sections = [...document.querySelectorAll('[data-doc-section]')];

        if (!('IntersectionObserver' in window)) return;

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                links.forEach((link) => {
                    const active = link.getAttribute('href') === `#${entry.target.id}`;
                    link.classList.toggle('active', active);
                    if (active) link.setAttribute('aria-current', 'location');
                    else link.removeAttribute('aria-current');
                });
            });
        }, { rootMargin: '-18% 0px -70% 0px' });

        sections.forEach((section) => observer.observe(section));
    },

    addKeyboardShortcut() {
        window.addEventListener('keydown', (event) => {
            const target = event.target;
            const isTyping = target instanceof HTMLElement
                && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

            if (event.key === '/' && !isTyping && !event.metaKey && !event.ctrlKey && !event.altKey) {
                event.preventDefault();
                this.$refs.search?.focus();
            }

            if (event.key === 'Escape') {
                this.closeSidebar();
                if (document.activeElement === this.$refs.search) this.clearSearch();
            }
        });
    },

    addCopyButtons() {
        document.querySelectorAll('.docs-code').forEach((block) => {
            const button = block.querySelector('[data-copy-button]');
            const code = block.querySelector('code');
            if (!button || !code) return;

            button.addEventListener('click', async () => {
                const original = button.textContent;
                try {
                    await navigator.clipboard.writeText(code.innerText);
                    button.textContent = 'Copied';
                } catch (_) {
                    button.textContent = 'Copy unavailable';
                }
                window.setTimeout(() => { button.textContent = original; }, 1400);
            });
        });
    },
}));

Alpine.start();
