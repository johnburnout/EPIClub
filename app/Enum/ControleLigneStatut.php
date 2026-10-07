<?php

declare(strict_types=1);

namespace Epiclub\Enum;

/**
 * Statuts possibles d'une ligne de contrôle.
 *
 * Correspond à l'ENUM MySQL `controle_ligne.statut`
 * (cf. migrations/001_initial.sql) :
 *   enum('a_controler','controle_ok','controle_ko','hors_service')
 *
 * Utilisé par ControleController::updateLigne() pour valider la
 * valeur soumise via POST (protection contre le mass-assignment).
 *
 * Le switch de ControleController::cloturer() s'appuie aussi sur cet
 * enum, ce qui rend l'exhaustivité vérifiable par les outils statiques
 * (PHPStan, IDE) et évite un `default: break` silencieux.
 */
enum ControleLigneStatut: string
{
    case A_CONTROLER  = 'a_controler';
    case CONTROLE_OK  = 'controle_ok';
    case CONTROLE_KO  = 'controle_ko';
    case HORS_SERVICE = 'hors_service';

    /**
     * Libellé humain (utilisable en template ou flash).
     */
    public function label(): string
    {
        return match ($this) {
            self::A_CONTROLER  => 'À contrôler',
            self::CONTROLE_OK  => 'Contrôle OK',
            self::CONTROLE_KO  => 'Contrôle KO',
            self::HORS_SERVICE => 'Hors service',
        };
    }

    /**
     * Indique si le statut est terminal (utilisé pour cloturer()).
     *
     * Un statut terminal signifie que la ligne a été traitée par le
     * contrôleur et ne peut plus être modifiée tant que le contrôle
     * n'est pas clôturé.
     */
    public function isTerminal(): bool
    {
        return $this !== self::A_CONTROLER;
    }
}