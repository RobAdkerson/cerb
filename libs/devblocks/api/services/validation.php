<?php
class Exception_DevblocksValidationError extends Exception_Devblocks {};

class _DevblocksValidationField {
	public $_name = null;
	public $_label = null;
	public $_type = null;
	
	function __construct($name, $label=null) {
		$this->_name = $name;
		$this->_label = $label ?: $name;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeNumber
	 */
	function bit() {
		$this->_type = new _DevblocksValidationTypeNumber();
		
		// Defaults for bit type
		return $this->_type
			->setMin(0)
			->setMax(1)
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeContext
	 */
	function context() {
		$this->_type = new _DevblocksValidationTypeContext();
		$validation = DevblocksPlatform::services()->validation();
		return $this->_type
			->addFormatter($validation->formatters()->context())
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeFloat
	 */
	function float() {
		$this->_type = new _DevblocksValidationTypeFloat();
		return $this->_type;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeNumber
	 */
	function id() {
		$this->_type = new _DevblocksValidationTypeNumber();
		
		// Defaults for id type
		return $this->_type
			->setMin(0)
			->setMax('32 bits')
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeNumber
	 */
	function idArray() {
		$this->_type = new _DevblocksValidationTypeIdArray();
		return $this->_type;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeString
	 */
	function image($type='image/png', $min_width=1, $min_height=1, $max_width=1000, $max_height=1000, $max_size=512000) {
		$validation = DevblocksPlatform::services()->validation();
		$this->_type = new _DevblocksValidationTypeString();
		return $this->_type
			->setMaxLength(512000)
			->addValidator($validation->validators()->image($type, $min_width, $min_height, $max_width, $max_height, $max_size))
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeNumber
	 */
	function number() {
		$this->_type = new _DevblocksValidationTypeNumber();
		return $this->_type;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeString
	 */
	function string() {
		$this->_type = new _DevblocksValidationTypeString();
		return $this->_type
			->setMaxLength(255)
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeStringOrArray
	 */
	function stringOrArray() {
		$this->_type = new _DevblocksValidationTypeStringOrArray();
		return $this->_type
			->setMaxLength(255)
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeNumber
	 */
	function timestamp() {
		$this->_type = new _DevblocksValidationTypeNumber();
		return $this->_type
			->setMin(0)
			->setMax('32 bits') // 4 unsigned bytes
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeNumber
	 */
	function uint($bytes=4) {
		$this->_type = new _DevblocksValidationTypeNumber();
		return $this->_type
			->setMin(0)
			->setMax('32 bits')
			;
	}
	
	/**
	 * 
	 * @return _DevblocksValidationTypeString
	 */
	function url() {
		$validation = DevblocksPlatform::services()->validation();
		$this->_type = new _DevblocksValidationTypeString();
		
		return $this->_type
			->setMaxLength(255)
			->addValidator($validation->validators()->url())
			;
	}
}

class _DevblocksFormatters {
	function context($allow_empty=false) {
		return function(&$value, &$error=null) use ($allow_empty) {
			if(empty($value) && $allow_empty)
				return true;
			
			// If this is a valid fully qualified extension ID, accept
			if(false != (Extension_DevblocksContext::get($value, false)))
				return true;
			
			// Otherwise, check aliases
			if(false != ($context_mft = Extension_DevblocksContext::getByAlias($value, false))) {
				$value = $context_mft->id;
				return true;
			}
			
			$error = "is not a valid context.";
			return false;
		};
	}
}

class _DevblocksValidators {
	function context($allow_empty=false) {
		return function($value, &$error=null) use ($allow_empty) {
			if(empty($value) & $allow_empty)
				return true;
			
			if(false == ($context_ext = Extension_DevblocksContext::getByAlias($value, false))) {
				$error = sprintf("is not a valid context (%s)", $value);
				return false;
			}
			
			return true;
		};
	}
	
	function contextId($context, $allow_empty=false) {
		return function($value, &$error=null) use ($context, $allow_empty) {
			if(!is_numeric($value)) {
				$error = "must be an ID.";
				return false;
			}
			
			$id = intval($value);
			
			if(empty($id)) {
				if($allow_empty) {
					return true;
					
				} else {
					$error = "must not be blank.";
					return false;
				}
			}
			
			$models = CerberusContexts::getModels($context, [$id]);
			
			if(!isset($models[$id])) {
				$error = "is not a valid target record.";
				return false;
			}
			
			return true;
		};
	}
	
	function contextIds($context, $allow_empty=false) {
		return function($value, &$error=null) use ($context, $allow_empty) {
			if(!is_array($value)) {
				$error = "must be an array of IDs.";
				return false;
			}
			
			$ids = DevblocksPlatform::sanitizeArray($value, 'int');
			
			if(!$allow_empty && empty($ids)) {
				$error = sprintf("must not be blank.");
				return false;
			}
			
			$models = CerberusContexts::getModels($context, $ids);
			
			foreach($ids as $id) {
				if(!isset($models[$id])) {
					$error = sprintf("value (%d) is not a valid target record.", $id);
					return false;
				}
			}
			
			return true;
		};
	}
	
	function email() {
		return function($value, &$error=null) {
			if(!is_string($value)) {
				$error = "must be a string.";
				return false;
			}
			
			$validated_emails = CerberusUtils::parseRfcAddressList($value);
			
			if(empty($validated_emails) || !is_array($validated_emails)) {
				$error = "is invalid. It must be a properly formatted email address.";
				return false;
			}
			
			return true;
		};
	}
	
	function emails() {
		return function($value, &$error=null) {
			if(!is_string($value)) {
				$error = "must be a comma-separated string.";
				return false;
			}
			
			$validated_emails = CerberusMail::parseRfcAddresses($value);
			
			if(empty($validated_emails) || !is_array($validated_emails)) {
				$error = "is invalid. It must be a comma-separated list of properly formatted email address.";
				return false;
			}
			
			return true;
		};
	}
	
	function extension($extension_class) {
		return function($value, &$error=null) use ($extension_class) {
			if(false == ($ext = $extension_class::get($value))) {
				$error = sprintf("(%s) is not a valid extension ID on (%s).",
					$value,
					$extension_class::POINT
				);
				return false;
			}
			
			return true;
		};
	}
	
	function image($type='image/png', $min_width=1, $min_height=1, $max_width=1000, $max_height=1000, $max_size=512000) {
		return function($value, &$error=null) use ($type, $min_width, $max_width, $min_height, $max_height, $max_size) {
			if(!is_string($value)) {
				$error = "must be a base64-encoded string.";
				return false;
			}
			
			if(!DevblocksPlatform::strStartsWith($value, 'data:')) {
				$error = "must be start with 'data:'.";
				return false;
			}
			
			$imagedata = substr($value, 5);
			
			if(!DevblocksPlatform::strStartsWith($imagedata,'image/png;base64,')) {
				$error = "must be a base64-encoded string with the format 'data:image/png;base64,<data>";
				return false;
			}
			
			// Decode it to binary
			if(false == ($imagedata = base64_decode(substr($imagedata, 17)))) {
				$error = "does not contain a valid base64 encoded PNG image.";
				return false;
			}
			
			if(strlen($imagedata) > $max_size) {
				$error = sprintf("must be smaller than %s (%s).", DevblocksPlatform::strPrettyBytes($max_size), DevblocksPlatform::strPrettyBytes(strlen($imagedata)));
				return false;
			}
			
			// Verify the "magic bytes": 89 50 4E 47 0D 0A 1A 0A
			if('89504e470d0a1a0a' != bin2hex(substr($imagedata,0,8))) {
				$error = "is not a valid PNG image.";
				return false;
			}
			
			// Test dimensions
			
			if(false == ($size_data = getimagesizefromstring($imagedata)) || !is_array($size_data)) {
				$error = "error. Failed to determine image dimensions.";
				return false;
			}
			
			if($size_data[0] < $min_width) {
				$error = sprintf("must be at least %dpx in width (%dpx).", $min_width, $size_data[0]);
				return false;
			}
			
			if($size_data[0] > $max_width) {
				$error = sprintf("must be no more than %dpx in width (%dpx).", $max_width, $size_data[0]);
				return false;
			}
			
			if($size_data[1] < $min_height) {
				$error = sprintf("must be at least %dpx in height (%dpx).", $min_height, $size_data[1]);
				return false;
			}
			
			if($size_data[1] > $max_height) {
				$error = sprintf("must be no more than %dpx in height (%dpx).", $max_height, $size_data[1]);
				return false;
			}
			
			return true;
		};
	}
	
	function language() {
		return function($value, &$error) {
			if(empty($value))
				return true;
			
			$translate = DevblocksPlatform::getTranslationService();
			$locales = $translate->getLocaleStrings();
			
			if(!$value || !isset($locales[$value])) {
				$error = sprintf("(%s) is not a valid language. Format like: en_US",
					$value
				);
				return false;
			}
			
			return true;
		};
	}
	
	function timezone() {
		return function($value, &$error) {
			if(empty($value))
				return true;
			
			$date = DevblocksPlatform::services()->date();
			$timezones = $date->getTimezones();
			
			if(!in_array($value, $timezones)) {
				$error = sprintf("(%s) is not a valid timezone. Format like: America/Los_Angeles",
					$value
				);
				return false;
			}
			
			return true;
		};
	}
	
	function url() {
		return function($value, &$error=null) {
			if(!is_string($value)) {
				$error = "must be a string.";
				return false;
			}
			
			// Empty strings are fine
			if(0 == strlen($value))
				return true;
			
			if(false == filter_var($value, FILTER_VALIDATE_URL)) {
				$error = "is not a valid URL. It must start with http:// or https://";
				return false;
			}
			
			if(false == ($url_parts = parse_url($value))) {
				$error = "is not a valid URL.";
				return false;
			}
			
			if(!isset($url_parts['scheme'])) {
				$error = "must start with http:// or https://";
				return false;
			}
			
			if(!in_array(strtolower($url_parts['scheme']), ['http','https'])) {
				$error = "is invalid. The URL must start with http:// or https://";
				return false;
			}
			
			return true;
		};
	}
}

class _DevblocksValidationType {
	public $_data = [
		'editable' => true,
		'required' => false,
	];
	
	function __construct() {
		return $this;
	}
	
	function setEditable($bool) {
		$this->_data['editable'] = $bool ? true : false;
		return $this;
	}
	
	function isRequired() {
		return @$this->_data['required'] ? true : false;
	}
	
	function setRequired($bool) {
		// If required, it also must not be empty
		$this->setNotEmpty(true);
		
		$this->_data['required'] = $bool ? true : false;
		return $this;
	}
	
	function setUnique($dao_class) {
		$this->_data['unique'] = true;
		$this->_data['dao_class'] = $dao_class;
		return $this;
	}
	
	function setNotEmpty($bool) {
		$this->_data['not_empty'] = $bool ? true : false;
		return $this;
	}
	
	function addFormatter($callable) {
		if(!is_callable($callable))
			return false;
		
		if(!isset($this->_data['formatters']))
			$this->_data['formatters'] = [];
		
		$this->_data['formatters'][] = $callable;
		return $this;
	}
	
	function addValidator($callable) {
		if(!is_callable($callable))
			return false;
		
		if(!isset($this->_data['validators']))
			$this->_data['validators'] = [];
		
		$this->_data['validators'][] = $callable;
		return $this;
	}
}

class _DevblocksValidationTypeContext extends _DevblocksValidationType {
	
}

class _DevblocksValidationTypeFloat extends _DevblocksValidationType {
	function __construct() {
		parent::__construct();
		return $this;
	}
	
	function setMin($n) {
		if(!is_numeric($n))
			return false;
		
		$this->_data['min'] = floatval($n);
		return $this;
	}
	
	function setMax($n) {
		if(!is_numeric($n))
			return false;
		
		$this->_data['max'] = floatval($n);
		return $this;
	}
}

class _DevblocksValidationTypeIdArray extends _DevblocksValidationType {
	function __construct() {
		parent::__construct();
		return $this;
	}
}

class _DevblocksValidationTypeNumber extends _DevblocksValidationType {
	function __construct() {
		parent::__construct();
		return $this;
	}
	
	function setMin($n) {
		if(is_string($n)) {
			$n = DevblocksPlatform::strBitsToInt($n);
		}
		
		if(!is_numeric($n))
			return false;
		
		$this->_data['min'] = $n;
		return $this;
	}
	
	function setMax($n) {
		if(is_string($n)) {
			$n = DevblocksPlatform::strBitsToInt($n);
		}
		
		if(!is_numeric($n))
			return false;
		
		$this->_data['max'] = $n;
		return $this;
	}
}

class _DevblocksValidationTypeString extends _DevblocksValidationType {
	function setMaxLength($length) {
		if(is_string($length)) {
			$length = DevblocksPlatform::strBitsToInt($length);
		}
		
		if(!is_numeric($length))
			return false;
		
		$this->_data['length'] = $length;
		return $this;
	}
	
	function setPossibleValues(array $possible_values) {
		$this->_data['possible_values'] = $possible_values;
		return $this;
	}
}

class _DevblocksValidationTypeStringOrArray extends _DevblocksValidationType {
	function setMaxLength($length) {
		if(is_string($length)) {
			$length = DevblocksPlatform::strBitsToInt($length);
		}
		
		$this->_data['length'] = intval($length);
		return $this;
	}
	
	function setPossibleValues(array $possible_values) {
		$this->_data['possible_values'] = $possible_values;
		return $this;
	}
}

class _DevblocksValidationService {
	private $_fields = [];
	
	/**
	 * 
	 * @param string $name
	 * @return _DevblocksValidationField
	 */
	function addField($name, $label=null) {
		$this->_fields[$name] = new _DevblocksValidationField($name, $label);
		return $this->_fields[$name];
	}
	
	/*
	 * @return _DevblocksValidationField[]
	 */
	function getFields() {
		return $this->_fields;
	}
	
	/**
	 * return _DevblocksFormatters
	 */
	function formatters() {
		return new _DevblocksFormatters();
	}
	
	/**
	 * return _DevblocksValidators
	 */
	function validators() {
		return new _DevblocksValidators();
	}
	
	// (ip, email, phone, etc)
	function validate(_DevblocksValidationField $field, &$value, $scope=[]) {
		$field_name = $field->_name;
		$field_label = $field->_label;
		
		if(false == ($class_name = get_class($field->_type)))
			throw new Exception_DevblocksValidationError("'%s' has an invalid type.", $field_label);
		
		$data = $field->_type->_data;
		
		if(isset($data['editable'])) {
			if(!$data['editable'])
				throw new Exception_DevblocksValidationError(sprintf("'%s' is not editable.", $field_label));
		}
		
		if(isset($data['not_empty']) && $data['not_empty'] && 0 == strlen($value)) {
			throw new Exception_DevblocksValidationError(sprintf("'%s' must not be blank.", $field_label));
		}
		
		if(isset($data['formatters']) && is_array($data['formatters']))
		foreach($data['formatters'] as $formatter) {
			if(!is_callable($formatter)) {
				throw new Exception_DevblocksValidationError(sprintf("'%s' has an invalid formatter.", $field_label));
			}
			
			if(!$formatter($value, $error)) {
				throw new Exception_DevblocksValidationError(sprintf("'%s' %s", $field_label, $error));
			}
		}
		
		if(isset($data['validators']) && is_array($data['validators']))
		foreach($data['validators'] as $validator) {
			if(!is_callable($validator)) {
				throw new Exception_DevblocksValidationError(sprintf("'%s' has an invalid validator.", $field_label));
			}
			
			if(!$validator($value, $error)) {
				throw new Exception_DevblocksValidationError(sprintf("'%s' %s", $field_label, $error));
			}
		}
		
		// [TODO] This would have trouble if we were bulk updating a unique field
		if(isset($data['unique']) && $data['unique']) {
			@$dao_class = $data['dao_class'];
			
			if(empty($dao_class))
				throw new Exception_DevblocksValidationError("'%s' has an invalid unique constraint.", $field_label);
			
			if(isset($scope['id'])) {
				$results = $dao_class::getWhere(sprintf("%s = %s AND id != %d", $dao_class::escape($field_name), $dao_class::qstr($value), $scope['id']), null, null, 1);
			} else {
				$results = $dao_class::getWhere(sprintf("%s = %s", $dao_class::escape($field_name), $dao_class::qstr($value)), null, null, 1);
			}
			
			if(!empty($results)) {
				throw new Exception_DevblocksValidationError(sprintf("A record already exists with this '%s' (%s). It must be unique.", $field_label, $value));
			}
		}
		
		switch($class_name) {
			case '_DevblocksValidationTypeContext':
				if(!is_string($value) || false == ($context_ext = Extension_DevblocksContext::getByAlias($value, false))) {
					throw new Exception_DevblocksValidationError(sprintf("'%s' is not a valid context (%s).", $field_label, $value));
				}
				// [TODO] Filter to specific contexts for certain fields
				break;
				
			case '_DevblocksValidationTypeId':
			case '_DevblocksValidationTypeNumber':
				if(!is_numeric($value)) {
					throw new Exception_DevblocksValidationError(sprintf("'%s' must be a number (%s: %s).", $field_label, gettype($value), $value));
				}
				
				if($data) {
					if(isset($data['min']) && $value < $data['min']) {
						throw new Exception_DevblocksValidationError(sprintf("'%s' must be >= %u (%u)", $field_label, $data['min'], $value));
					}
					
					if(isset($data['max']) && $value > $data['max']) {
						throw new Exception_DevblocksValidationError(sprintf("'%s' must be <= %u (%u)", $field_label, $data['max'], $value));
					}
				}
				break;
				
			case '_DevblocksValidationTypeIdArray':
				
				if(!is_array($value)) {
					throw new Exception_DevblocksValidationError(sprintf("'%s' must be an array of IDs (%s).", $field_label, gettype($value)));
				}
				
				$values = $value;
				
				foreach($values as $id) {
					if(!is_numeric($id)) {
						throw new Exception_DevblocksValidationError(sprintf("Value '%s' must be a number (%s: %s).", $field_label, gettype($id), $id));
					}
				}
				break;
				
			case '_DevblocksValidationTypeString':
				if(!is_null($value) && !is_string($value)) {
					throw new Exception_DevblocksValidationError(sprintf("'%s' must be a string (%s).", $field_label, gettype($value)));
				}
				
				if($data) {
					if(isset($data['length']) && strlen($value) > $data['length']) {
						throw new Exception_DevblocksValidationError(sprintf("'%s' must be no longer than %d characters.", $field_label, $data['length']));
					}
					
					if(isset($data['possible_values']) && !in_array($value, $data['possible_values'])) {
						throw new Exception_DevblocksValidationError(sprintf("'%s' must be one of: %s", $field_label, implode(', ', $data['possible_values'])));
					}
				}
				break;
				
			case '_DevblocksValidationTypeStringOrArray':
				if(!is_null($value) && !is_string($value) && !is_array($value)) {
					throw new Exception_DevblocksValidationError(sprintf("'%s' must be a string or array (%s).", $field_label, gettype($value)));
				}
				
				if(!is_array($value)) {
					$values = [$value];
				} else {
					$values = $value;
				}
				
				if($data) {
					foreach($values as $v) {
						if(isset($data['length']) && strlen($v) > $data['length']) {
							throw new Exception_DevblocksValidationError(sprintf("'%s' must be no longer than %d characters.", $field_label, $data['length']));
						}
						
						if(isset($data['possible_values']) && !in_array($v, $data['possible_values'])) {
							throw new Exception_DevblocksValidationError(sprintf("'%s' must be one of: %s", $field_label, implode(', ', $data['possible_values'])));
						}
					}
				}
				break;
		}
		
		return true;
	}
};