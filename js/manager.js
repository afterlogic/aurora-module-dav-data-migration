'use strict';

module.exports = function (appData) {
	var
		TextUtils = require('%PathToCoreWebclientModule%/js/utils/Text.js'),

		Ajax = require('%PathToCoreWebclientModule%/js/Ajax.js'),
		Api = require('%PathToCoreWebclientModule%/js/Api.js'),
		App = require('%PathToCoreWebclientModule%/js/App.js'),
		Screens = require('%PathToCoreWebclientModule%/js/Screens.js'),

		Settings = require('modules/%ModuleName%/js/Settings.js')
	;

	Settings.init(appData);

	if (App.isUserNormalOrTenant()) {
		function startMigration () {
			Screens.showLoading(TextUtils.i18n('%MODULENAME%/INFO_DATA_MIGRATION'));
			Ajax.send('%ModuleName%', 'Migrate', {}, function (response, request, status) {
				if (status === 503) {
					startMigration();
				} else {
					Screens.hideLoading();
					if (response.Result) {
						Screens.showReport(TextUtils.i18n('%MODULENAME%/REPORT_DATA_MIGRATION'));
					} else {
						Api.showErrorByCode(response, TextUtils.i18n('%MODULENAME%/ERROR_DATA_MIGRATION'));
					}
				}
			}, this);
		}

		return {
			start: function (ModulesManager) {
				if (!Settings.Migrated) {
					setTimeout(startMigration);
				}
			}
		};
	}

	return null;
};
