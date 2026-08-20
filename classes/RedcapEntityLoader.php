<?php

namespace Stanford\MICA;

require_once __DIR__ . "/EntitySchemaException.php";

/**
 * Makes the redcap_entity framework's classes available in this request.
 *
 * Necessary because REDCap's module framework loads a module's PHP only when something instantiates
 * it. redcap_entity requires its `classes/` at file scope, so `\REDCapEntity\EntityFactory` exists
 * only if *something* in this request already touched that module - which is true on a Control
 * Center page and false on a survey page, in a cron, and in a CLI script.
 *
 * The failure mode is a bare "Class not found" at the moment of first use, which is exactly the
 * moment you least want it: a participant has finished their session, the transcript is written, and
 * the scan job insert dies. This is one call, and every entry into the Entity framework goes through
 * it - the alternative is remembering, per call site, which is how the scan-queue store came to be
 * missing it while the schema manager had it.
 */
class RedcapEntityLoader
{
    public const PREFIX = 'redcap_entity';

    private static bool $loaded = false;

    /**
     * @throws EntitySchemaException when redcap_entity is absent, disabled, or unloadable
     */
    public static function ensureLoaded(): void
    {
        if (self::$loaded && class_exists('\REDCapEntity\EntityFactory')) {
            return;
        }

        if (!class_exists('\REDCapEntity\EntityFactory')) {
            // Instantiating the module is what loads its file. The return value is unused.
            \ExternalModules\ExternalModules::getModuleInstance(self::PREFIX);
        }

        if (!class_exists('\REDCapEntity\EntityFactory')) {
            throw new EntitySchemaException(
                'The REDCap Entity module (' . self::PREFIX . ') could not be loaded, so MICA has '
                . 'nowhere to store its scan queue. Enable it in the Control Center. If it is '
                . 'already enabled, its version in redcap_external_module_settings does not match a '
                . 'directory on disk.'
            );
        }

        self::$loaded = true;
    }
}
