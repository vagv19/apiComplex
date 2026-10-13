const publicAsset = (path: string) => `${import.meta.env.BASE_URL}${path}`

export const siteAssets = {
  branding: {
    logo: publicAsset('assets/images/branding/logo.jpg'),
    logoWhite: publicAsset('assets/images/branding/logo.jpg'),
    favicon: publicAsset('assets/images/branding/logo.jpg'),
  },
  placeholders: {
    tournament: publicAsset('assets/images/tournaments/tournament-placeholder.svg'),
    team: publicAsset('assets/images/teams/team-placeholder.svg'),
    image: publicAsset('assets/images/ui/image-placeholder.svg'),
    facility: publicAsset('assets/images/ui/image-placeholder.svg'),
  },
  facilities: {
    cancha: publicAsset('assets/images/facilities/cancha.jpg'),
  },
} as const
