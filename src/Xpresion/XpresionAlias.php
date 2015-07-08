<?php


namespace Xpresion;


class XpresionAlias
{
    public static function get_entry(&$entries, $id)
    {
        if ($id && $entries && isset($entries[$id]))
        {
            // walk/bypass aliases, if any
            $entry = $entries[ $id ];
            while (($entry instanceof XpresionAlias) && (isset($entries[$entry->alias])))
            {
                $id = $entry->alias;
                // circular reference
                if ($entry === $entries[ $id ]) return false;
                $entry = $entries[ $id ];
            }
            return $entry;
        }
        return false;
    }

    public function __construct($alias)
    {
        $this->alias = strval($alias);
    }

    public function __destruct()
    {
        $this->alias = null;
    }
}