/**
 * Light/dark switch. The choice is stored in localStorage; with no choice, the system setting wins.
 * The page applies the stored theme before paint (see the inline script in the layouts).
 */
const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

export function themeToggle() {
    return {
        dark: false,

        init() {
            this.sync();
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => this.sync());
        },

        sync() {
            const theme = document.documentElement.dataset.theme;
            this.dark = theme ? theme === 'dark' : prefersDark();
        },

        get label() {
            return this.dark ? 'Switch to light mode' : 'Switch to dark mode';
        },

        toggle() {
            this.dark = !this.dark;
            document.documentElement.dataset.theme = this.dark ? 'dark' : 'light';

            try {
                localStorage.setItem('dcc-theme', document.documentElement.dataset.theme);
            } catch {}
        },
    };
}
