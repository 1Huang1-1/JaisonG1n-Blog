import type { DiaryItem } from "../../../data/diary";

export interface MomentCardProps {
	moment: DiaryItem;
	title: string;
	detailHref: string;
	viewLabel: string;
	moodLabel: string;
	imageLabel: string;
}
