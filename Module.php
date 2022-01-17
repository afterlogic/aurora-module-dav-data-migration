<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\DavDataMigration;

use Afterlogic\DAV\Server;
use Aurora\Api;
use Aurora\Modules\Calendar\Module as CalendarModule;
use Aurora\Modules\Contacts\Classes\VCard\Helper;
use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\Contact;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\Modules\Core\Module as CoreModule;
use Aurora\Modules\Dav\Client;
use Aurora\Modules\Mail\Models\MailAccount;
use Aurora\Modules\Mail\Module as MailModule;
use Sabre\VObject\Reader;

use function Sabre\Uri\split;

/**
 * Integrate SabreDav framework into Aurora platform.
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @license https://afterlogic.com/products/common-licensing Afterlogic Software License
 * @copyright Copyright (c) 2019, Afterlogic Corp.
 *
 * @package Modules
 */
class Module extends \Aurora\System\Module\AbstractModule
{
	protected $aRequireModules = ['Calendar', 'Contacts'];
	
	public $client = null;

	public function __construct($sPath, $sVersion = '1.0') {
		parent::__construct($sPath, $sVersion);
	}

	protected function getClient($oAccount = null) {
		if (!isset($this->client)) {
			if (!isset($oAccount)) {	
				$sUserPublicId = Api::getAuthenticatedUserPublicId();
				$oAccount = CoreModule::getInstance()->GetAccountUsedToAuthorize($sUserPublicId);
			}
			if ($oAccount) {
				$sLogin = $oAccount->getLogin();
				$sPassword = $oAccount->getPassword();
				$sUrl = $this->getConfig('DavServerUrl');
				
				$this->client = new Client($sUrl, $sLogin, $sPassword);
			}
		}

		return $this->client;
	}

	protected function getHrefValue($aElements) {
		$sValue = null;
	
		foreach ($aElements as $aElement) {
			if ($aElement['name'] === '{DAV:}href') {
				$sValue = \trim($aElement['value'], '');
				break;
			}
		}
	
		return $sValue;
	}

	protected function getCurrentPrincipalUri() {
		$mResult = '';
		if (isset($this->client)) {

			$mResult = $this->getHrefValue(
				$this->client->GetCurrentPrincipal()
			);
		}

		return $mResult;
	}

	protected function migrateContacts($sPrincipalUri, $oAccount) {
		if (isset($sPrincipalUri)) {
			$abHomeSet = $this->getHrefValue(
				$this->client->GetAddressBookHomeSet($sPrincipalUri)
			);
			if (isset($abHomeSet)) {
				$aBooks = $this->client->GetAddressBooks($abHomeSet);
				
				foreach ($aBooks as $key => $props) {
					if ($props['{DAV:}resourcetype']->is('{'.\Sabre\CardDAV\Plugin::NS_CARDDAV.'}addressbook')) {
						list(, $sAddressBookId) = split($key);
						$oAddressBook = ContactsModule::Decorator()->GetAddressBook(
							$oAccount->IdUser, 
							$sAddressBookId
						);

						if (!$oAddressBook) {
							ContactsModule::Decorator()->CreateAddressBook(
								$props['{DAV:}displayname'],
								$oAccount->IdUser, 
								$sAddressBookId
							);
							$oAddressBook = ContactsModule::Decorator()->GetAddressBook(
								$oAccount->IdUser, 
								$sAddressBookId
							);
						}

						$aVCards = $this->client->GetVcards($key);

						foreach ($aVCards as $aVCard) {
							$sHref = $aVCard['href'];
							$aPathInfo = pathinfo($sHref);
							$sUUID = $aPathInfo['filename'];
							$oContact = Contact::where('IdUser', $oAccount->IdUser)
								->where('UUID', $sUUID)
								->where('AddressBookId', $oAddressBook->Id)
								->first();

							if (!isset($oContact) || empty($oContact)) {
								$oVCard = Reader::read($aVCard['data']);
								$aContactData = Helper::GetContactDataFromVcard($oVCard, $sUUID);
								$aContactData['Storage'] = StorageType::AddressBook . $oAddressBook->Id;
								ContactsModule::Decorator()->CreateContact($aContactData, $oAccount->IdUser);
							}
						}
					}
				}
			}
		}
	}
	
	protected function migrateCalendars($sPrincipalUri, $oAccount) {
		$oCalendarDecorator = CalendarModule::Decorator();
		if ($oCalendarDecorator && isset($sPrincipalUri)) {
	
			$calHomeSet = $this->getHrefValue(
				$this->client->GetCalendarHomeSet($sPrincipalUri)
			);
			if (isset($calHomeSet)) {

				$aCalendars = $this->client->getCalendars($calHomeSet);
				
				foreach ($aCalendars as $calendar) {
					list(, $sCalendarId) = split($calendar->Id);
					$oCalendar = $oCalendarDecorator->GetCalendar(
						$oAccount->IdUser, 
						$sCalendarId
					);

					$creationResult = false;

					if (!$oCalendar) {
						$creationResult = $oCalendarDecorator->CreateCalendar(
							$oAccount->IdUser, 
							$calendar->DisplayName, 
							$calendar->Description, 
							$calendar->Color, 
							$sCalendarId
						);
					} else {
						$creationResult = true;
					}

					if ($creationResult) {
						Server::setUser(Api::getUserPublicIdById($oAccount->IdUser));
						$vCalendar = Server::getNodeForPath($calendar->Id);
						if ($vCalendar) {
							$aEvents = $this->client->getEvents($calendar->Id);
							foreach ($aEvents as $aEvent) {
								if (!$vCalendar->childExists($aEvent['href'])) {
									$vCalendar->createFile($aEvent['href'], $aEvent['data']);
								}
							}
						}
					}
				}
			}
		}
	}

	/***** private functions *****/
	/**
	 * Initializes Module.
	 *
	 * @ignore
	 */
	public function init() {
		$this->subscribeEvent('Core::Login::after', [$this, 'onAfterLogin']);
	}

	public function Migrate($Account)
	{
		$this->getClient($Account);
		$sCurrentPrincipalUri = $this->getCurrentPrincipalUri();
		$this->migrateContacts($sCurrentPrincipalUri, $Account);
		$this->migrateCalendars($sCurrentPrincipalUri, $Account);
	}

	public function onAfterLogin($aArgs, &$mResult)
	{
		if ($mResult) {
//			$oUser = CoreModule::Decorator()->GetUserUnchecked($aArgs['UserId']);
			$oUser = Api::getAuthenticatedUser();
			if ($oUser) {
				$bMigrated = $oUser->getExtendedProp($this->GetName() . '::Migrated', false);
				if (!$bMigrated) {
					$oAccount = CoreModule::Decorator()->GetAccountUsedToAuthorize($oUser->PublicId);
	//				$oAccount = MailModule::Decorator()->GetAccountByEmail($aArgs['IncomingLogin'], $aArgs['UserId']);
					if ($oAccount instanceof MailAccount && $oAccount->UseToAuthorize) {
						$prev = Api::skipCheckUserRole(true);
						$this->Migrate($oAccount);
						Api::skipCheckUserRole($prev);

						$oUser->setExtendedProp($this->GetName() . '::Migrated', true);
						$oUser->save();

					}
				}
			}
		}
	}
}