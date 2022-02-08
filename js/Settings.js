'use strict';

var
	_ = require('underscore'),

	Types = require('%PathToCoreWebclientModule%/js/utils/Types.js')
;

module.exports = {
	Migrated: true,

	/**
	 * Initializes settings from AppData object sections.
	 * 
	 * @param {Object} appData Object contained modules settings.
	 */
	init: function (appData)
	{
		var appDataSection = appData['%ModuleName%'];

		if (!_.isEmpty(appDataSection)) {
			console.log('oAppDataSection.Migrated', appDataSection.Migrated);
			this.Migrated = Types.pBool(appDataSection.Migrated, this.Migrated);
		}
	}
};
