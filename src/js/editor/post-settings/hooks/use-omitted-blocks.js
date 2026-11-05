/**
 * Tracks blocks omitted from the beehiiv newsletter payload.
 */
import { useSelect } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as editorStore } from '@wordpress/editor';

import {
	isOmittedFromNewsletter,
	sendsGroupsAsSections,
} from '../../../shared/supported-blocks';
import {
	META_BEEHIIV_GROUP_SECTIONS,
	META_BEEHIIV_POST_ID,
} from '../../../shared/meta';

/**
 * @typedef {import('@wordpress/blocks').WPBlock} WPBlock
 */

/**
 * @typedef {Object} BlockAncestor
 * @property {string} clientId Block client ID.
 * @property {string} name     Block name.
 */

/**
 * Resolve the outermost omitted block for an unsupported block in the tree.
 *
 * @param {BlockAncestor[]} ancestors     Root-to-parent chain for the block.
 * @param {string}          blockClientId Client ID of the unsupported block.
 * @param {boolean}         usesSections  Whether the post sends Groups as sections.
 * @return {string} Client ID of the omitted block to highlight.
 */
function resolveOmittedBlockTarget( ancestors, blockClientId, usesSections ) {
	for ( const ancestor of ancestors ) {
		if ( isOmittedFromNewsletter( ancestor, usesSections ) ) {
			return ancestor.clientId;
		}
	}

	return blockClientId;
}

/**
 * Collect client IDs for blocks omitted from the newsletter.
 *
 * @param {WPBlock[]} blocks       Parsed top-level blocks.
 * @param {boolean}   usesSections Whether the post sends Groups as sections.
 * @return {Set<string>} Client IDs of omitted blocks to highlight.
 */
export function collectOmittedBlockClientIds( blocks, usesSections = false ) {
	/** @type {Set<string>} */
	const targets = new Set();

	/**
	 * @param {WPBlock[]}       blockList
	 * @param {BlockAncestor[]} ancestors Root-to-parent chain.
	 */
	function visit( blockList, ancestors = [] ) {
		for ( const block of blockList ) {
			if ( isOmittedFromNewsletter( block, usesSections ) ) {
				targets.add(
					resolveOmittedBlockTarget(
						ancestors,
						block.clientId,
						usesSections
					)
				);
				continue;
			}

			if ( block.innerBlocks?.length ) {
				visit( block.innerBlocks, [
					...ancestors,
					{ clientId: block.clientId, name: block.name },
				] );
			}
		}
	}

	visit( blocks );

	return targets;
}

/**
 * Whether the sync sends the current post's Group blocks as beehiiv sections.
 *
 * Reads the same post meta record the server sync uses.
 *
 * @param {Function} select Data registry select.
 * @return {boolean} True when Groups are sent as sections.
 */
function selectSendsGroupsAsSections( select ) {
	const meta = select( editorStore ).getEditedPostAttribute( 'meta' );

	return sendsGroupsAsSections(
		meta?.[ META_BEEHIIV_GROUP_SECTIONS ],
		meta?.[ META_BEEHIIV_POST_ID ]
	);
}

/**
 * Number of blocks that will be omitted from the newsletter.
 *
 * @return {number} Count of omitted top-level block targets.
 */
export function useOmittedBlockCount() {
	return useSelect( ( select ) => {
		const blocks = select( blockEditorStore ).getBlocks();

		return collectOmittedBlockClientIds(
			blocks,
			selectSendsGroupsAsSections( select )
		).size;
	}, [] );
}

/**
 * Whether a block should show omitted-block canvas indicators.
 *
 * @param {string} clientId Block client ID.
 * @return {boolean} Whether the block should be visually marked as omitted.
 */
export function useIsOmittedBlock( clientId ) {
	return useSelect(
		( select ) => {
			const blockEditor = select( blockEditorStore );
			const targets = collectOmittedBlockClientIds(
				blockEditor.getBlocks(),
				selectSendsGroupsAsSections( select )
			);

			if ( ! targets.has( clientId ) ) {
				return false;
			}

			const parents = blockEditor.getBlockParents( clientId );

			return ! parents.some( ( parentId ) => targets.has( parentId ) );
		},
		[ clientId ]
	);
}
