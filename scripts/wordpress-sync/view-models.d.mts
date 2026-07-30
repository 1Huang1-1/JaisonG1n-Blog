export interface FriendViewModel { name: string; icon: string; description: string; avatar: string; url: string; tags: string[]; }
export interface AnnouncementViewModel { title: string; content: string; closable: boolean; dismissKey: string; link: { enable: boolean; text: string; url: string; external: boolean }; }
export function announcementDismissKey(value: Omit<AnnouncementViewModel, "dismissKey">): string;
export function adaptFriends(items: unknown[]): FriendViewModel[];
export function adaptAnnouncements(items: unknown[]): AnnouncementViewModel[];
