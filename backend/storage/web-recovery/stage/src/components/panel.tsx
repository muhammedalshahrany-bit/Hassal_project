import React from 'react';
import { Save } from 'lucide-react';

/**
 * The few controls the administration screens share.
 *
 * They live together because three screens need exactly the same wording of a
 * banner, the same disabled state on an action that writes to the server, and
 * the same empty look when a value was never recorded. Duplicating them is how
 * one screen ends up showing an error the others swallow.
 *
 * The dark-theme values are written here once, for the same reason. A reviewer
 * reading registration certificates at night is looking at these surfaces for
 * hours, and contrast that is set per-screen is contrast that drifts between
 * screens. `--color-hassal-*` is defined in `src/index.css`.
 */

export type Banner = { kind: 'ok' | 'error' | 'warn'; text: string } | null;

/**
 * `warn` is its own kind because a refusal and an inconvenient truth are not the
 * same event. "The key was issued, and no route authenticates it" succeeded — the
 * message that follows it must not wear the colour of a failure.
 */
const BANNER_TONES: Record<Banner extends null ? never : NonNullable<Banner>['kind'], string> = {
  ok: 'bg-emerald-50 border-emerald-200 text-emerald-900 dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-200',
  error: 'bg-rose-50 border-rose-200 text-rose-900 dark:bg-rose-950/40 dark:border-rose-800 dark:text-rose-200',
  warn: 'bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-200',
};

export const PanelShell: React.FC<{ title: string; hint: string; children: React.ReactNode }> = ({
  title,
  hint,
  children,
}) => (
  <section className="bg-white border border-slate-200 rounded-xl p-5 space-y-4 dark:bg-hassal-surface dark:border-hassal-line">
    <div className="border-b border-slate-100 pb-3 dark:border-hassal-line">
      <h3 className="text-sm font-bold text-slate-900 dark:text-slate-100">{title}</h3>
      <p className="text-[11px] text-slate-500 pt-1 leading-relaxed dark:text-slate-400">{hint}</p>
    </div>
    {children}
  </section>
);

export const LabeledSelect: React.FC<{
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: { value: string; label: string }[];
}> = ({ label, value, onChange, options }) => (
  <label className="min-w-0 space-y-1">
    <span className="block text-[11px] font-bold text-slate-600 dark:text-slate-300">{label}</span>
    <select
      value={value}
      onChange={(event) => onChange(event.target.value)}
      className="w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs font-medium text-slate-800 dark:border-hassal-line dark:bg-hassal-raised dark:text-slate-100 sm:w-auto sm:min-w-[9rem]"
    >
      {options.map((option) => (
        <option key={`${option.value}-${option.label}`} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  </label>
);

/**
 * One field treatment for every form on the platform.
 *
 * The border turns rose only when the server refused this field, and the refusal
 * is printed under it rather than in a banner above the form: a person fixing a
 * rejected value needs the reason attached to the box they are typing in.
 */
export const TextInput: React.FC<{
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  wide?: boolean;
  /** id of a <datalist> offering suggested values; typing is never limited to it. */
  list?: string;
  type?: 'text' | 'number' | 'date' | 'password' | 'email';
  min?: string | number;
  max?: string | number;
  multiline?: boolean;
  rows?: number;
  /** A rejection the server returned for this field, placed under the field. */
  error?: string;
  /** Said out loud rather than only greyed: a disabled field must explain itself. */
  disabled?: boolean;
  help?: string;
}> = ({ label, value, onChange, placeholder, wide, list, type = 'text', min, max, multiline, rows = 3, error, disabled, help }) => {
  /**
   * Widths. A field used to be `w-52` at every size, which is 208px of a 360px
   * phone screen and leaves the label, the padding and the next control to fight
   * over the rest. Under the first breakpoint the control takes the row it is
   * on — dense filter bars keep their side-by-side layout from `sm` up, where
   * the pointer is a mouse and the row is wide enough for it.
   */
  const field = `bg-slate-50 border rounded-lg px-2.5 py-2 text-xs leading-relaxed focus:bg-white focus:outline-none ${
    error ? 'border-rose-300' : 'border-slate-200'
  } dark:bg-hassal-raised dark:text-slate-100 dark:focus:bg-hassal-night ${
    error ? 'dark:border-rose-700' : 'dark:border-hassal-line'
  } ${wide ? 'w-full' : 'w-full sm:w-52'} ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`;

  return (
    <label className="space-y-1 block">
      <span className="block text-[11px] font-bold text-slate-600 dark:text-slate-300">{label}</span>
      {multiline ? (
        <textarea
          value={value}
          disabled={disabled}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
          rows={rows}
          className={field}
        />
      ) : (
        <input
          type={type}
          value={value}
          min={min}
          max={max}
          disabled={disabled}
          onChange={(event) => onChange(event.target.value)}
          placeholder={placeholder}
          list={list}
          className={field}
        />
      )}
      {help && !error ? <span className="block text-[10px] text-slate-400 dark:text-slate-500">{help}</span> : null}
      {error ? <span className="block text-[10px] text-rose-700 font-semibold dark:text-rose-300">{error}</span> : null}
    </label>
  );
};

export const DecisionButton: React.FC<{
  children: React.ReactNode;
  onClick: () => void;
  busy?: boolean;
  tone?: 'primary' | 'default';
}> = ({ children, onClick, busy, tone = 'default' }) => (
  <button
    onClick={onClick}
    disabled={busy}
    className={`text-xs font-bold px-3 py-2 rounded-lg border flex items-center gap-1.5 disabled:opacity-40 cursor-pointer ${
      tone === 'primary'
        ? 'bg-emerald-600 text-white border-emerald-600 hover:bg-emerald-700 dark:bg-emerald-700 dark:border-emerald-700 dark:hover:bg-emerald-600'
        : 'bg-white text-slate-800 border-slate-300 hover:bg-slate-100 dark:bg-hassal-raised dark:text-slate-100 dark:border-hassal-line dark:hover:bg-hassal-night'
    }`}
  >
    {tone === 'primary' ? <Save className="w-3.5 h-3.5" /> : null}
    <span>{children}</span>
  </button>
);

/** A recorded fact, with the label outside and the server's value inside. */
export const Fact: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div className="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 dark:bg-hassal-raised dark:border-hassal-line">
    <div className="text-[10px] text-slate-500 dark:text-slate-400">{label}</div>
    <div className="text-[11px] font-bold text-slate-800 truncate dark:text-slate-100">{value}</div>
  </div>
);

export const BannerView: React.FC<{ banner: Banner }> = ({ banner }) => {
  if (!banner) return null;
  return (
    <div className={`text-xs rounded-lg px-3 py-2.5 border ${BANNER_TONES[banner.kind] ?? BANNER_TONES.warn}`}>
      {banner.text}
    </div>
  );
};

/**
 * Not an error: something the platform wants the reader to know before acting,
 * which is why it is amber and not rose.
 */
export const ErrorNote: React.FC<{ text: string }> = ({ text }) => (
  <div className="text-xs rounded-lg px-3 py-2.5 border bg-amber-50 border-amber-200 text-amber-900 dark:bg-amber-950/40 dark:border-amber-800 dark:text-amber-200">
    {text}
  </div>
);

/** A value as the platform holds it, or the words that say it never was. */
export function recorded(value: string | null | undefined): string {
  const trimmed = typeof value === 'string' ? value.trim() : '';
  return trimmed || 'لم يُسجَّل';
}
