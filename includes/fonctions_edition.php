<?php
  
  /**
  * nettoie le code HTML pour affichage
  *
  */
  
  function escape($html) {
    if ($html) {
      return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
    else {
      return "";
    }
  }
  
  /**
    * Nettoie et valide une entrée utilisateur
    * @param mixed $input La donnée à nettoyer
    * @param string $type Le type de validation ('int' ou 'string')
  	* @param array $options Les options de validation
    * @return mixed La donnée nettoyée
    */
  
    function sanitizeInput($input, $type = 'int', $options = []) {
        switch ($type) {
            case 'int':
                $options += ['min_range' => 0];
                return filter_var($input, FILTER_VALIDATE_INT, ['options' => $options]);
            case 'string':
                return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
            case 'array':
                return is_array($input) ? array_map('trim', $input) : [];
            default:
                return null;
        }
    }
	
	/**
    * Nettoie et valide une entrée utilisateur
    * @param string $ecart La durée avant date du jour à renvoyer
    * @return mixed La date renvoyée
    */
	
	function dateAnterieure(string $ecart) {
		try {
			$date_retour = strtotime(implode(" -", [date('Ymd'), $ecart]));
			return ['date' => date('Ymd',$date_retour), 'success' => true];
		}
		catch (Exception $e) {
			return ['date' => date('Ymd'), 'success' => false];
		}
	}