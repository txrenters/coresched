import { Link2, MapPin, Phone, PhoneOutgoing, Video } from '@lucide/vue';
import type { Component } from 'vue';

const locationIcons: Record<string, Component> = {
    microsoft_teams: Video,
    google_meet: Video,
    phone: Phone,
    invitee_phone: PhoneOutgoing,
    in_person: MapPin,
    custom_link: Link2,
};

/**
 * Get the icon for a location type, so a phone call is not shown as a video
 * call. Anything unrecognised falls back to the video camera.
 */
export function locationIcon(locationType: string): Component {
    return locationIcons[locationType] ?? Video;
}
