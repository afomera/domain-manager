/**
 * A small calendar date picker. The value is a "YYYY-MM-DD" string (or "" for none), so it binds
 * straight to a Livewire string property via x-modelable + wire:model.
 *
 * Dates are handled as plain year/month/day numbers to avoid time-zone drift.
 */

const pad = (number) => String(number).padStart(2, '0');

const toIso = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;

const fromIso = (value) => {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value ?? '');

    return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
};

const startOfToday = () => {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
};

const addDays = (date, days) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);

const addMonths = (date, months) => {
    const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
    const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();

    return new Date(target.getFullYear(), target.getMonth(), Math.min(date.getDate(), lastDay));
};

const sameDay = (a, b) => a && b && toIso(a) === toIso(b);

export function datePicker({ min = null, presets = [] } = {}) {
    return {
        value: '',
        open: false,
        presets,
        weekdays: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
        // The month on screen and the keyboard-focused day.
        cursor: startOfToday(),

        get selected() {
            return fromIso(this.value);
        },

        get minDate() {
            return min === 'today' ? startOfToday() : fromIso(min);
        },

        get label() {
            return this.selected
                ? this.selected.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
                : '';
        },

        /**
         * "in 3 months", "tomorrow", "2 days ago" — the part that matters for an expiry date.
         */
        get relative() {
            if (!this.selected) {
                return '';
            }

            const days = Math.round((this.selected - startOfToday()) / 86_400_000);
            const format = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

            if (Math.abs(days) < 45) {
                return format.format(days, 'day');
            }

            return Math.abs(days) < 548 ? format.format(Math.round(days / 30.44), 'month') : format.format(Math.round(days / 365.25), 'year');
        },

        get monthLabel() {
            return this.cursor.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        },

        /**
         * Six weeks of days covering the cursor's month, padded with neighbouring months.
         */
        get days() {
            const first = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1);
            const start = addDays(first, -first.getDay());

            return Array.from({ length: 42 }, (_, index) => {
                const date = addDays(start, index);

                return {
                    iso: toIso(date),
                    day: date.getDate(),
                    inMonth: date.getMonth() === this.cursor.getMonth(),
                    isToday: sameDay(date, startOfToday()),
                    isSelected: sameDay(date, this.selected),
                    isFocused: sameDay(date, this.cursor),
                    isDisabled: this.isDisabled(date),
                };
            });
        },

        isDisabled(date) {
            return this.minDate !== null && date < this.minDate;
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.cursor = this.selected ?? this.minDate ?? startOfToday();
            this.open = true;
            this.$nextTick(() => this.focusCursor());
        },

        close(returnFocus = true) {
            this.open = false;

            if (returnFocus) {
                // After the popover hides, so focus isn't lost along with the focused day button.
                this.$nextTick(() => this.$refs.trigger?.focus());
            }
        },

        pick(iso) {
            const date = fromIso(iso);

            if (!date || this.isDisabled(date)) {
                return;
            }

            this.value = iso;
            this.close();
        },

        applyPreset({ days = 0, months = 0 }) {
            this.pick(toIso(addMonths(addDays(startOfToday(), days), months)));
        },

        clear() {
            this.value = '';
            this.close();
        },

        shiftMonth(months) {
            this.cursor = addMonths(this.cursor, months);
            this.$nextTick(() => this.focusCursor());
        },

        /**
         * Grid keyboard support: arrows move by day/week, Page Up/Down by month, Home/End to week edges.
         */
        onGridKey(event) {
            const moves = {
                ArrowLeft: () => addDays(this.cursor, -1),
                ArrowRight: () => addDays(this.cursor, 1),
                ArrowUp: () => addDays(this.cursor, -7),
                ArrowDown: () => addDays(this.cursor, 7),
                PageUp: () => addMonths(this.cursor, -1),
                PageDown: () => addMonths(this.cursor, 1),
                Home: () => addDays(this.cursor, -this.cursor.getDay()),
                End: () => addDays(this.cursor, 6 - this.cursor.getDay()),
            };

            if (moves[event.key]) {
                event.preventDefault();
                this.cursor = moves[event.key]();
                this.$nextTick(() => this.focusCursor());
            } else if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                this.pick(toIso(this.cursor));
            }
        },

        focusCursor() {
            this.$refs.grid?.querySelector(`[data-date="${toIso(this.cursor)}"]`)?.focus();
        },
    };
}
