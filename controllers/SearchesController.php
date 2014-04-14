<?php
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2013 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

require('ApiController.php');

class SearchesController extends ApiController {
	public function searches() {
		if ($this->queryParams['apiVersion'] < 2) {
			$this->e404();
		}
		
		// Check for general library access
		if (!$this->permissions->canAccess($this->objectLibraryID)) {
			$this->e403();
		}
		
		if ($this->isWriteMethod()) {
			// Check for library write access
			if (!$this->permissions->canWrite($this->objectLibraryID)) {
				$this->e403("Write access denied");
			}
			
			// Make sure library hasn't been modified
			if (!$this->singleObject) {
				$libraryTimestampChecked = $this->checkLibraryIfUnmodifiedSinceVersion();
			}
			
			Zotero_Libraries::updateVersionAndTimestamp($this->objectLibraryID);
		}
		
		$results = array();
		$totalResults = 0;
		
		// Single search
		if ($this->singleObject) {
			$this->allowMethods(array('GET', 'PUT', 'DELETE'));
			
			$search = Zotero_Searches::getByLibraryAndKey($this->objectLibraryID, $this->objectKey);
			if (!$search) {
				$this->e404("Search not found");
			}
			
			if ($this->method == 'PUT' || $this->method == 'DELETE') {
				$objectTimestampChecked =
					$this->checkObjectIfUnmodifiedSinceVersion(
						$search, $this->method == 'DELETE'
				);
				
				$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
				
				// Update search
				if ($this->method == 'PUT') {
					$obj = $this->jsonDecode($this->body);
					$changed = Zotero_Searches::updateFromJSON(
						$search,
						$obj,
						$this->queryParams,
						$objectTimestampChecked ? 0 : 2
					);
					
					// If not updated, return the original library version
					if (!$changed) {
						$this->libraryVersion = Zotero_Libraries::getOriginalVersion(
							$this->objectLibraryID
						);
					}
					
					if ($cacheKey = $this->getWriteTokenCacheKey()) {
						Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
					}
				}
				// Delete search
				else if ($this->method == 'DELETE') {
					Zotero_Searches::delete($this->objectLibraryID, $this->objectKey);
				}
				else {
					throw new Exception("Unexpected method $this->method");
				}
				
				$this->e204();
			}
			
			$this->libraryVersion = $search->version;
			$this->responseXML = $search->toAtom($this->queryParams);
		}
		// Multiple searches
		else {
			$this->allowMethods(array('GET', 'POST', 'DELETE'));
			
			$this->libraryVersion = Zotero_Libraries::getUpdatedVersion($this->objectLibraryID);
			
			// Create a search
			if ($this->method == 'POST') {
				$this->queryParams['format'] = 'writereport';
				
				$obj = $this->jsonDecode($this->body);
				$results = Zotero_Searches::updateMultipleFromJSON(
					$obj,
					$this->objectLibraryID,
					$this->queryParams,
					$this->userID,
					$libraryTimestampChecked ? 0 : 1,
					null
				);
				
				if ($cacheKey = $this->getWriteTokenCacheKey()) {
					Z_Core::$MC->set($cacheKey, true, $this->writeTokenCacheTime);
				}
			}
			// Delete searches
			else if ($this->method == 'DELETE') {
				Zotero_DB::beginTransaction();
				foreach ($this->queryParams['searchKey'] as $searchKey) {
					Zotero_Searches::delete($this->objectLibraryID, $searchKey);
				}
				Zotero_DB::commit();
				$this->e204();
			}
			// Display searches
			else {
				$title = "Searches";
				$results = Zotero_Searches::search($this->objectLibraryID, $this->queryParams);
			}
			
			if ($results && isset($results['results'])) {
				$totalResults = $results['total'];
				$results = $results['results'];
			}
				
			switch ($this->queryParams['format']) {
			case 'atom':
				$this->responseXML = Zotero_Atom::createAtomFeed(
					$this->getFeedNamePrefix($this->objectLibraryID) . $title,
					$this->uri,
					$results,
					$totalResults,
					$this->queryParams,
					$this->permissions
				);
				break;
			
			case 'keys':
				header("Content-Type: text/plain");
				echo implode("\n", $results) . "\n";
				break;
			
			case 'versions':
			case 'writereport':
				if ($this->queryParams['pprint']) {
					header("Content-Type: text/plain");
				}
				else {
					header("Content-Type: application/json");
				}
				echo Zotero_Utilities::formatJSON($results, $this->queryParams['pprint']);
				break;
			
			default:
				throw new Exception("Invalid 'format' value '"
					. $this->queryParams['format'] . "'", Z_ERROR_INVALID_INPUT);
			}
		}
		
		$this->end();
	}
}
