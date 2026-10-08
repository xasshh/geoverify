import { addProtocol } from 'maplibre-gl';
import { Protocol } from 'pmtiles';

/**
 * The pmtiles protocol, registered once for the life of the tab.
 *
 * MapLibre resolves protocols from a module level registry, so registering per
 * map would either throw or quietly replace the handler for a map still using
 * it. Shared here by every field map (buildings and area capture alike), so
 * the archives each adds stay reachable.
 */
const protocol = new Protocol();
let registered = false;

export function registerProtocol(): Protocol {
    if (!registered) {
        addProtocol('pmtiles', protocol.tile);
        registered = true;
    }

    return protocol;
}
