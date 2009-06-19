<?php

/**
 * SpotlightAJaxSequence
 *
 * This is an AJAX-action that will alter the sequence of the spotlightitems
 *
 * @package		backend
 * @subpackage	spotlight
 *
 * @author 		Tijs Verkoyen <tijs@netlash.com>
 * @since		2.0
 */
class SpotlightAjaxSequence extends BackendBaseAJAXAction
{
	/**
	 * Execute the action
	 *
	 * @return	void
	 */
	public function execute()
	{
		// get data from POST
		$newIdSequence = SpoonFilter::getPostValue('new_id_sequence', null, '');

		// validate the data
		if($newIdSequence == '') $this->output(self::ERROR, null, BL::getError('InvalidParameters'));

		// split the ids
		$newIdsSequence = explode(',', $newIdSequence);

		// update the sequence
		$success = (bool) BackendSpotlightModel::updateSequence($newIdsSequence);

		// everything went fine
		if($success) $this->output(self::OK, null, BL::getMessage('SequenceChanged'));

		// fallback
		$this->output(self::ERROR, null, BL::getError('SomethingWentWrong'));
	}
}

?>