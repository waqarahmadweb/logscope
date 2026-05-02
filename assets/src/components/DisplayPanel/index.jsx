/**
 * Display settings section — admin-wide defaults for the log viewer:
 * default page size, default severity filter, and timestamp timezone.
 *
 * The defaults are read by the store on bootstrap (see
 * `assets/src/store/index.js`) and by `useUrlQuerySync` for the initial
 * filter state. Saving and reloading is the canonical way to see them
 * applied — they don't reactively re-seed the running view, because doing
 * so would clobber whatever the admin had on screen.
 */
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	Notice,
	RadioControl,
	TextControl,
} from '@wordpress/components';

import { STORE_KEY } from '../../store';
import { SEVERITY_TOKENS, severityLabel } from '../../utils/severity';

const PER_PAGE_MIN = 10;
const PER_PAGE_MAX = 500;

export const DISPLAY_FIELD_KEYS = [
	'default_per_page',
	'default_severity_filter',
	'timestamp_tz',
];

/**
 * Pure validator over the display draft. Returns `{ valid, errors }`
 * keyed by field name. Exported so SettingsPanel can gate Save without
 * re-deriving the rules.
 */
export function validateDisplay( draft ) {
	const errors = {};
	if ( ! draft ) {
		return { valid: true, errors };
	}
	const perPage = Number( draft.default_per_page );
	if (
		! Number.isFinite( perPage ) ||
		perPage < PER_PAGE_MIN ||
		perPage > PER_PAGE_MAX
	) {
		errors.default_per_page = sprintf(
			/* translators: 1: minimum rows, 2: maximum rows. */
			__( 'Rows per page must be between %1$d and %2$d.', 'logscope' ),
			PER_PAGE_MIN,
			PER_PAGE_MAX
		);
	}
	const tz = String( draft.timestamp_tz || '' );
	if ( tz !== 'site' && tz !== 'utc' ) {
		errors.timestamp_tz = __( 'Pick site time or UTC.', 'logscope' );
	}
	return { valid: Object.keys( errors ).length === 0, errors };
}

export default function DisplayPanel() {
	const draft = useSelect(
		( select ) => select( STORE_KEY ).getSettingsDraft(),
		[]
	);
	const { setSettingsDraft } = useDispatch( STORE_KEY );

	if ( ! draft ) {
		return null;
	}

	const { errors } = validateDisplay( draft );
	const selectedSeverities = String( draft.default_severity_filter || '' )
		.split( ',' )
		.map( ( s ) => s.trim() )
		.filter( Boolean );

	const toggleSeverity = ( token, on ) => {
		const set = new Set( selectedSeverities );
		if ( on ) {
			set.add( token );
		} else {
			set.delete( token );
		}
		// Preserve canonical SEVERITY_TOKENS order so the stored CSV is
		// stable regardless of click order.
		const next = SEVERITY_TOKENS.filter( ( t ) => set.has( t ) );
		setSettingsDraft( { default_severity_filter: next.join( ',' ) } );
	};

	return (
		<div className="logscope-display-panel">
			<h2 className="logscope-settings-panel__section-title">
				{ __( 'Display', 'logscope' ) }
			</h2>
			<p className="logscope-settings-panel__section-lead">
				{ __(
					'Defaults applied when the log viewer first loads. Changes take effect on next page load.',
					'logscope'
				) }
			</p>

			<div className="logscope-display-panel__field">
				<TextControl
					type="number"
					label={ __( 'Default rows per page', 'logscope' ) }
					value={ String( draft.default_per_page ?? '' ) }
					min={ PER_PAGE_MIN }
					max={ PER_PAGE_MAX }
					onChange={ ( next ) =>
						setSettingsDraft( {
							default_per_page: next === '' ? '' : Number( next ),
						} )
					}
					help={ __(
						'How many entries the log viewer fetches per page. 10–500.',
						'logscope'
					) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
				{ errors.default_per_page && (
					<div style={ { marginTop: 10 } }>
						<Notice status="error" isDismissible={ false }>
							{ errors.default_per_page }
						</Notice>
					</div>
				) }
			</div>

			<div className="logscope-display-panel__field">
				<fieldset>
					<legend className="logscope-display-panel__legend">
						{ __( 'Default severity filter', 'logscope' ) }
					</legend>
					<p className="logscope-display-panel__field-help">
						{ __(
							'Severities ticked here are pre-selected when the log viewer first loads. Leave all unticked to show every severity.',
							'logscope'
						) }
					</p>
					<div className="logscope-display-panel__checks">
						{ SEVERITY_TOKENS.map( ( token ) => (
							<CheckboxControl
								key={ token }
								label={ severityLabel( token ) }
								checked={ selectedSeverities.includes( token ) }
								onChange={ ( on ) =>
									toggleSeverity( token, on )
								}
								__nextHasNoMarginBottom
							/>
						) ) }
					</div>
				</fieldset>
			</div>

			<div className="logscope-display-panel__field">
				<RadioControl
					label={ __( 'Timestamp display', 'logscope' ) }
					selected={ draft.timestamp_tz || 'site' }
					options={ [
						{
							label: __( 'Site time (recommended)', 'logscope' ),
							value: 'site',
						},
						{ label: __( 'UTC', 'logscope' ), value: 'utc' },
					] }
					onChange={ ( next ) =>
						setSettingsDraft( { timestamp_tz: next } )
					}
				/>
				<p className="logscope-display-panel__field-help">
					{ __(
						'Site time uses the WordPress site timezone. UTC shows raw debug.log timestamps without conversion.',
						'logscope'
					) }
				</p>
				{ errors.timestamp_tz && (
					<div style={ { marginTop: 10 } }>
						<Notice status="error" isDismissible={ false }>
							{ errors.timestamp_tz }
						</Notice>
					</div>
				) }
			</div>
		</div>
	);
}
