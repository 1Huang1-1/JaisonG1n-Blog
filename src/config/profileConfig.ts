import type { ProfileConfig } from "../types/config";

// 个人资料配置
export const profileConfig: ProfileConfig = {
	avatar: "assets/images/avatar.webp", // 相对于 /src 目录。如果以 '/' 开头，则相对于 /public 目录
	name: "JaisonG1n",
	bio: "Code, AI and Everything in Between",
	typewriter: {
		enable: true, // 启用个人简介打字机效果
		speed: 80, // 打字速度（毫秒）
	},
	links: [
		{
			name: "Bilibili",
			icon: "fa7-brands:bilibili",
			url: "https://space.bilibili.com/3546754974747002?spm_id_from=333.1007.0.0",
		},
		{
			name: "Gitee",
			icon: "mdi:git",
			url: "https://gitee.com/l-guarding-l",
		},
		{
			name: "GitHub",
			icon: "fa7-brands:github",
			url: "https://github.com/1Huang1-1",
		},
		// {
		// 	name: "Codeberg",
		// 	icon: "simple-icons:codeberg",
		// 	url: "https://codeberg.org",
		// },
		// {
		// 	name: "Discord",
		// 	icon: "fa7-brands:discord",
		// 	url: "https://discord.gg/MqW6TcQtVM",
		// },
	],
};
