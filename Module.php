<?php
/**
 * This code is licensed under AGPLv3 license or Afterlogic Software License
 * if commercial version of the product was purchased.
 * For full statements of the licenses see LICENSE-AFTERLOGIC and LICENSE-AGPL3 files.
 */

namespace Aurora\Modules\DavDataMigration;

use Afterlogic\DAV\Constants;
use Afterlogic\DAV\Server;
use Aurora\Api;
use Aurora\Modules\Calendar\Module as CalendarModule;
use Aurora\Modules\Contacts\Classes\VCard\Helper;
use Aurora\Modules\Contacts\Enums\StorageType;
use Aurora\Modules\Contacts\Models\Contact;
use Aurora\Modules\Contacts\Module as ContactsModule;
use Aurora\Modules\Core\Module as CoreModule;
use Aurora\Modules\Dav\Client;
use Aurora\Modules\DavContacts\Module as DavContactsModule;
use Aurora\Modules\Mail\Models\MailAccount;
use Aurora\Modules\Mail\Module as MailModule;
use Illuminate\Support\Facades\DB;
use Sabre\VObject\Reader;
use \Illuminate\Database\Capsule\Manager as Capsule;

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
						$sAddressBookId = rawurldecode($sAddressBookId);
						$bIsDefault = false;
						if ($sAddressBookId === 'default') {
							
							$sAddressBookId = \Afterlogic\DAV\Constants::ADDRESSBOOK_DEFAULT_NAME;
							$bIsDefault = true;

							$oAddressBook = DavContactsModule::Decorator()->getManager()->getAddressBook(
								$oAccount->IdUser, 
								$sAddressBookId
							);

							if (!$oAddressBook) {
								try {
									DavContactsModule::Decorator()->getManager()->createAddressBook(
										$oAccount->IdUser, 
										$sAddressBookId,
										\Afterlogic\DAV\Constants::ADDRESSBOOK_DEFAULT_DISPLAY_NAME
									);
								} catch (\PDOException $oEx) {}							
							}
						} else {

							$oAddressBook = ContactsModule::Decorator()->GetAddressBook(
								$oAccount->IdUser, 
								$sAddressBookId
							);

							if (!$oAddressBook) {
								try {
									ContactsModule::Decorator()->CreateAddressBook(
										$props['{DAV:}displayname'],
										$oAccount->IdUser, 
										$sAddressBookId
									);
								} catch (\PDOException $oEx) {}	
								
								$oAddressBook = ContactsModule::Decorator()->GetAddressBook(
									$oAccount->IdUser, 
									$sAddressBookId
								);
							}
						}

						$oDavAddressBook = DavContactsModule::Decorator()->getManager()->getAddressBook(
							$oAccount->IdUser, 
							$sAddressBookId
						);

						$aVCardsInfo = $this->client->GetVcardsInfo($key);
						$aVCardUrls = [];
						foreach ($aVCardsInfo as $aVCardInfo) {
							$sHref = $aVCardInfo['href'];
							$aVCardUrls[] = $key . $sHref;
						}

//						$aVCards = $this->client->GetVcards($key, $aVCardUrls);

						$aChunks = array_chunk($aVCardUrls, 200);
						foreach ($aChunks as $aVCardUrls) {

							$aVCards = $this->client->GetVcards($key, $aVCardUrls);
							foreach ($aVCards as $aVCard) {

								$sHref = $aVCard['href'];
								$aVCardUrls[] = $key . $sHref;
								$aPathInfo = pathinfo($sHref);
								$sUUID = $aPathInfo['filename'];
								$oQuery = Contact::where('IdUser', $oAccount->IdUser)
									->where('UUID', $sUUID);
								if (!$bIsDefault) {
									$oQuery = $oQuery->where('AddressBookId', $oAddressBook->Id);
								}
								
								if (!$oQuery->exists()) {

									$oVCard = Reader::read($aVCard['data']);
									$aContactData = Helper::GetContactDataFromVcard($oVCard, $sUUID);

									$aContactData['Storage'] = $bIsDefault ? StorageType::Personal : StorageType::AddressBook . $oAddressBook->Id;

//									ContactsModule::Decorator()->CreateContact($aContactData, $oAccount->IdUser);
									$oUser = Api::getAuthenticatedUser();
									$oNewContact = new Contact();
									$oNewContact->IdUser = $oUser->Id;
									$oNewContact->IdTenant = $oUser->IdTenant;
									$oNewContact->populate($aContactData, true);

									$bCreated = false;

									Capsule::schema()->getConnection()->beginTransaction();

									$oQuery = Contact::where('IdUser', $oNewContact->IdUser)
										->where('UUID', $sUUID);
					
									if (!$oNewContact->Storage === StorageType::AddressBook) {
										$oQuery = $oQuery->where('AddressBookId', $oNewContact->AddressBookId);
									}
									$oContact = $oQuery->lockForUpdate()->first();
									if (!$oContact) {
										$oNewContact->DateModified = date('Y-m-d H:i:s');
										$oNewContact->calculateETag();
										$oNewContact->setExtendedProp('DavContacts::UID', $sUUID);
										$bCreated = $oNewContact->save();
										if ($bCreated) {
											$oNewContact->addGroups(
												isset($aContactData['GroupUUIDs']) ? $aContactData['GroupUUIDs'] : null,
												isset($aContactData['GroupNames']) ? $aContactData['GroupNames'] : null,
												true
											);

										}
									}

									Capsule::schema()->getConnection()->commit();

									if ($bCreated && $oNewContact && $oDavAddressBook) {
										try {
											$oDavAddressBook->createFile($oNewContact->UUID . '.vcf', $aVCard['data']);
										} catch (\PDOException $oEx) {}
									}
								}
							}
						}
					}
				}
			}
		}
	}

	protected function getDefaultCalendar($iUserId) {
		
		$mResult = false;
		$oCalendarDecorator = CalendarModule::Decorator();
		if ($oCalendarDecorator) {
			$aCalendars = $oCalendarDecorator->GetCalendars($iUserId);
			foreach ($aCalendars['Calendars'] as $oCalendar) {
				if (\substr($oCalendar->Id, 0, \strlen(Constants::CALENDAR_DEFAULT_UUID)) === Constants::CALENDAR_DEFAULT_UUID) {
					$mResult = $oCalendar;
					break;
				}
			}
		}

		return $mResult;
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
					$sCalendarId = rawurldecode($sCalendarId);
					if ($sCalendarId === 'default') {
						$oCalendar = $this->getDefaultCalendar($oAccount->IdUser);
						if ($oCalendar) {
							$sCalendarId = $oCalendar->Id;
						}
					} else {
						$oCalendar = $oCalendarDecorator->GetCalendar(
							$oAccount->IdUser, 
							$sCalendarId
						);
					}

					$creationResult = false;

					if (!$oCalendar) {
						$creationResult = $oCalendarDecorator->CreateCalendar(
							$oAccount->IdUser, 
							$calendar->DisplayName, 
							$calendar->Description, 
							$calendar->Color, 
							$sCalendarId
						);
						if (!empty($calendar->Shares)) {
							$oCalendarDecorator->UpdateCalendarShare(
								$oAccount->IdUser, 
								$sCalendarId, 
								false, 
								\json_encode($calendar->Shares)
							);
						}
						
					} else {
						$creationResult = true;
					}

					if ($creationResult) {
						$sUserPublicId = Api::getUserPublicIdById($oAccount->IdUser);
						Server::setUser($sUserPublicId);
						$vCalendar = Server::getNodeForPath('calendars/' . $sCalendarId);
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

	public function init() 
	{
	}

	public function GetSettings()
	{
		\Aurora\System\Api::checkUserRoleIsAtLeast(\Aurora\System\Enums\UserRole::NormalUser);
		$mResult = [
			'Migrated' => false
		];
		$oUser = Api::getAuthenticatedUser();
		if ($oUser && $oUser->{$this->GetName() . '::Migrated'}) {
			$mResult['Migrated'] = true;
		}

		return $mResult;
	}

	public function Migrate()
	{
		$mResult = false;

		$oUser = Api::getAuthenticatedUser();
		if ($oUser && !$oUser->{$this->GetName() . '::Migrated'}) {

			$oAccount = CoreModule::getInstance()->GetAccountUsedToAuthorize($oUser->PublicId);

			if ($oAccount instanceof MailAccount && $oAccount->UseToAuthorize) {
				if (empty($this->getConfig('DavServerUrl', ''))) {
					Api::Log('The DavDataMigration module is not configured properly');
				} else {
					$this->getClient($oAccount);
					try {
						$sCurrentPrincipalUri = $this->getCurrentPrincipalUri();
					} catch (\Sabre\HTTP\ClientHttpException $oEx) {
						Api::Log('Can\'t connect to the following DAV server: ' . $this->getConfig('DavServerUrl'));

						return false;
					}
					try {
						$prev = Api::skipCheckUserRole(true);

						$this->migrateCalendars($sCurrentPrincipalUri, $oAccount);

						$this->migrateContacts($sCurrentPrincipalUri, $oAccount);

						Api::skipCheckUserRole($prev);
						$oUser->setExtendedProp($this->GetName() . '::Migrated', true);
						$oUser->save();
						$mResult = true;
					} catch (\Exception $oEx) {
						Api::LogException($oEx);
					}
				}
			}
		}

		return $mResult;
	}
}