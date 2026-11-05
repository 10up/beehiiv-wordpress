/**
 * Omitted Block Indicator PRD AC-005, AC-006, BR-004: Group blocks follow the section conversion.
 */
import {
	isOmittedFromNewsletter,
	sendsGroupsAsSections,
} from '../../src/js/shared/supported-blocks';
import { collectOmittedBlockClientIds } from '../../src/js/editor/post-settings/hooks/use-omitted-blocks';

// The editor stores are not needed for the pure tree walk.
jest.mock( '@wordpress/data', () => ( { useSelect: jest.fn() } ) );
jest.mock( '@wordpress/block-editor', () => ( { store: {} } ) );
jest.mock( '@wordpress/editor', () => ( { store: {} } ) );

const block = ( clientId, name, innerBlocks = [], attributes = {} ) => ( {
	clientId,
	name,
	innerBlocks,
	attributes,
} );

beforeEach( () => {
	window.beehiivBlockSupport = {
		supported: [ 'core/heading', 'core/paragraph', 'core/media-text' ],
		nestedOnly: [ 'core/button', 'core/list-item' ],
		snippetOnly: [ 'core/more' ],
		sectionBlock: 'core/group',
	};
} );

afterEach( () => {
	delete window.beehiivBlockSupport;
} );

describe( 'sendsGroupsAsSections', () => {
	it( 'uses the stored record when there is one', () => {
		expect( sendsGroupsAsSections( '1', 'post_linked' ) ).toBe( true );
		expect( sendsGroupsAsSections( '0', null ) ).toBe( false );
	} );

	it( 'treats a post with no beehiiv post yet as a first sync with sections', () => {
		expect( sendsGroupsAsSections( '', null ) ).toBe( true );
		expect( sendsGroupsAsSections( undefined, '' ) ).toBe( true );
	} );

	it( 'treats a linked post without a record as linked before the change', () => {
		expect( sendsGroupsAsSections( '', 'post_linked' ) ).toBe( false );
	} );
} );

describe( 'isOmittedFromNewsletter', () => {
	it( 'keeps a Group only when the post uses sections', () => {
		const group = block( 'g', 'core/group' );

		expect( isOmittedFromNewsletter( group, true ) ).toBe( false );
		expect( isOmittedFromNewsletter( group, false ) ).toBe( true );
	} );

	it( 'keeps Columns unsupported in both conversions', () => {
		expect(
			isOmittedFromNewsletter( block( 'c', 'core/columns' ), true )
		).toBe( true );
		expect(
			isOmittedFromNewsletter( block( 'c', 'core/columns' ), false )
		).toBe( true );
	} );
} );

describe( 'collectOmittedBlockClientIds', () => {
	const tree = () => [
		block( 'p0', 'core/paragraph' ),
		block( 'g1', 'core/group', [
			block( 'h1', 'core/heading' ),
			block( 's1', 'core/spacer' ),
			block( 'cols', 'core/columns', [
				block( 'col', 'core/column', [
					block( 'p-col', 'core/paragraph' ),
				] ),
			] ),
			block( 'g2', 'core/group', [ block( 's2', 'core/spacer' ) ] ),
		] ),
	];

	it( 'AC-005: marks unsupported blocks inside Groups, never the Groups, when sections are used', () => {
		const targets = collectOmittedBlockClientIds( tree(), true );

		expect( [ ...targets ].sort() ).toEqual( [ 'cols', 's1', 's2' ] );
	} );

	it( 'AC-006: marks only the outer Group, not its inner blocks, for a post linked before the change', () => {
		const targets = collectOmittedBlockClientIds( tree(), false );

		expect( [ ...targets ] ).toEqual( [ 'g1' ] );
	} );

	it( 'leaves a Group with only supported blocks unmarked when sections are used', () => {
		const targets = collectOmittedBlockClientIds(
			[
				block( 'g', 'core/group', [
					block( 'h', 'core/heading' ),
					block( 'p', 'core/paragraph' ),
				] ),
			],
			true
		);

		expect( targets.size ).toBe( 0 );
	} );

	it( 'treats a Group in a Media & Text content column the same way', () => {
		const blocks = [
			block( 'mt', 'core/media-text', [
				block( 'g', 'core/group', [ block( 's', 'core/spacer' ) ] ),
			] ),
		];

		expect( [ ...collectOmittedBlockClientIds( blocks, true ) ] ).toEqual( [
			's',
		] );
		expect( [ ...collectOmittedBlockClientIds( blocks, false ) ] ).toEqual(
			[ 'g' ]
		);
	} );
} );
