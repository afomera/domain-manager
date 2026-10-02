/**
 * Live updates for a single domain's page, from other tabs and devices (via Reverb).
 *
 * Handled in Alpine rather than a Livewire echo listener: if the domain was deleted elsewhere,
 * Livewire couldn't even rehydrate the page to run a listener, so we leave before asking it to.
 */
export function domainUpdates({ userId, domain, indexUrl }) {
    return {
        channel: `App.Models.User.${userId}`,
        handler: null,

        init() {
            if (!window.Echo) {
                return;
            }

            this.handler = (event) => {
                const affectsThisDomain = event.domain === null || event.domain === domain;

                if (!affectsThisDomain) {
                    return;
                }

                if (event.removed) {
                    window.Livewire.navigate(indexUrl);
                } else {
                    window.Livewire.dispatch('portfolio-refreshed');
                }
            };

            window.Echo.private(this.channel).listen('.portfolio.updated', this.handler);
        },

        // Called by Alpine when the page is left (including wire:navigate), so listeners don't pile up.
        destroy() {
            if (this.handler) {
                window.Echo?.private(this.channel).stopListening('.portfolio.updated', this.handler);
            }
        },
    };
}
