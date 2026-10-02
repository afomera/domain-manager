/**
 * x-tooltip: small, fast tooltips for icon buttons and terse controls.
 *
 *   <button aria-label="Settings" x-tooltip="'Settings'">…</button>
 *   <button x-tooltip="{ text: 'Search', kbd: '/' }">…</button>
 *   <button x-tooltip.top="'Saved to Cloudflare'">…</button>   (default placement is bottom)
 *
 * One shared bubble for the whole page. Shows after a short delay, but instantly when moving between
 * neighbouring triggers ("warm" for a moment after one hides). Keyboard focus shows it too; Escape,
 * click, scroll and pointer-leave hide it. Never shown for touch, where hover doesn't exist.
 */

const SHOW_DELAY = 450;
const WARM_WINDOW = 400;
const GAP = 6;
const EDGE = 8;

let bubble = null;
let activeTrigger = null;
let showTimer = null;
let lastHiddenAt = 0;

function ensureBubble() {
    if (bubble?.isConnected) {
        return bubble;
    }

    bubble = document.createElement('div');
    bubble.id = 'app-tooltip';
    bubble.setAttribute('role', 'tooltip');
    bubble.className =
        'pointer-events-none fixed top-0 left-0 z-[60] flex items-center gap-2 rounded-md bg-fg px-2 py-1 text-[12px] whitespace-nowrap text-bg opacity-0 shadow-md transition-opacity duration-100';
    bubble.hidden = true;
    document.body.appendChild(bubble);

    return bubble;
}

function render(content) {
    const { text, kbd } = typeof content === 'string' ? { text: content } : content ?? {};
    const el = ensureBubble();

    el.replaceChildren(document.createTextNode(text ?? ''));

    if (kbd) {
        const key = document.createElement('kbd');
        key.className = 'rounded border border-bg/30 px-1 font-mono text-[11px] leading-4 opacity-80';
        key.textContent = kbd;
        el.appendChild(key);
    }

    return text ?? '';
}

function position(trigger, placement) {
    const el = ensureBubble();
    const anchor = trigger.getBoundingClientRect();
    const box = el.getBoundingClientRect();
    const viewport = { width: window.innerWidth, height: window.innerHeight };

    let side = placement;

    // Flip when there's no room on the preferred side.
    if (side === 'bottom' && anchor.bottom + GAP + box.height > viewport.height - EDGE) side = 'top';
    if (side === 'top' && anchor.top - GAP - box.height < EDGE) side = 'bottom';
    if (side === 'right' && anchor.right + GAP + box.width > viewport.width - EDGE) side = 'left';
    if (side === 'left' && anchor.left - GAP - box.width < EDGE) side = 'right';

    let top;
    let left;

    if (side === 'top' || side === 'bottom') {
        top = side === 'bottom' ? anchor.bottom + GAP : anchor.top - GAP - box.height;
        left = anchor.left + anchor.width / 2 - box.width / 2;
    } else {
        top = anchor.top + anchor.height / 2 - box.height / 2;
        left = side === 'right' ? anchor.right + GAP : anchor.left - GAP - box.width;
    }

    // Keep it on screen.
    left = Math.min(Math.max(left, EDGE), viewport.width - box.width - EDGE);
    top = Math.min(Math.max(top, EDGE), viewport.height - box.height - EDGE);

    el.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
}

function show(trigger, content, placement) {
    const text = render(content);
    const el = ensureBubble();

    activeTrigger = trigger;
    el.hidden = false;
    position(trigger, placement);
    requestAnimationFrame(() => el.classList.replace('opacity-0', 'opacity-100'));

    // Only describe when it adds something beyond the accessible name.
    if (text && trigger.getAttribute('aria-label') !== text) {
        trigger.setAttribute('aria-describedby', el.id);
    }
}

function hide(trigger = activeTrigger) {
    clearTimeout(showTimer);

    if (!trigger || trigger !== activeTrigger) {
        return;
    }

    const el = ensureBubble();
    el.classList.replace('opacity-100', 'opacity-0');
    el.hidden = true;
    trigger.removeAttribute('aria-describedby');
    activeTrigger = null;
    lastHiddenAt = Date.now();
}

export function registerTooltip(Alpine) {
    Alpine.directive('tooltip', (el, { modifiers, expression }, { evaluateLater, effect, cleanup }) => {
        const placement = ['top', 'bottom', 'left', 'right'].find((side) => modifiers.includes(side)) ?? 'bottom';
        const getContent = evaluateLater(expression);
        let content = '';

        effect(() => getContent((value) => {
            content = value;

            // Live-update an open tooltip (e.g. a label that flips between "Light mode" and "Dark mode").
            if (activeTrigger === el) {
                render(content);
                position(el, placement);
            }
        }));

        const schedule = (event) => {
            if (event.pointerType === 'touch' || !content) {
                return;
            }

            clearTimeout(showTimer);
            const warm = Date.now() - lastHiddenAt < WARM_WINDOW;
            showTimer = setTimeout(() => show(el, content, placement), warm ? 0 : SHOW_DELAY);
        };

        const onFocus = () => {
            // Keyboard focus only; mouse clicks also focus buttons.
            if (el.matches(':focus-visible') && content) {
                show(el, content, placement);
            }
        };

        const onLeave = () => hide(el);
        const onKey = (event) => event.key === 'Escape' && hide(el);

        el.addEventListener('pointerenter', schedule);
        el.addEventListener('pointerleave', onLeave);
        el.addEventListener('pointerdown', onLeave);
        el.addEventListener('focus', onFocus);
        el.addEventListener('blur', onLeave);
        el.addEventListener('keydown', onKey);

        cleanup(() => {
            hide(el);
            el.removeEventListener('pointerenter', schedule);
            el.removeEventListener('pointerleave', onLeave);
            el.removeEventListener('pointerdown', onLeave);
            el.removeEventListener('focus', onFocus);
            el.removeEventListener('blur', onLeave);
            el.removeEventListener('keydown', onKey);
        });
    });

    window.addEventListener('scroll', () => hide(), { passive: true, capture: true });
    document.addEventListener('livewire:navigating', () => hide());
}
