import { datePicker } from './date-picker';
import { domainUpdates } from './live-updates';
import { themeToggle } from './theme';
import { registerTooltip } from './tooltip';

// Livewire ships Alpine; register our components before it starts.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('datePicker', datePicker);
    window.Alpine.data('domainUpdates', domainUpdates);
    window.Alpine.data('themeToggle', themeToggle);
    registerTooltip(window.Alpine);
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';
