/**
 * Spread AI design tokens — taken from the live site (site-assets/css/home-v2.css)
 * navy #0B1526 · blue #0C87EF · teal #2EE3CC · off-white #F4F7FC · gradient 135° teal → blue
 */
export const colors = {
  bg: '#F4F7FC',
  bgAlt: '#EEF5FB',
  card: '#FFFFFF',
  ink: '#0B1526',
  ink2: '#27324A',
  mute: '#4A5468',
  mute2: '#5B6478',
  mute3: '#8391A6',
  line: '#E6EDF5',
  line2: '#DCE6F0',
  blue: '#0C87EF',
  blueDark: '#0A6FD8',
  teal: '#2EE3CC',
  violet: '#B7A6FF',
  navy2: '#12305A',
  white: '#FFFFFF',
  success: '#12A579',
  successBg: '#E3F8F0',
  warn: '#C77700',
  warnBg: '#FFF4DE',
  danger: '#D93B4A',
  dangerBg: '#FDE8EA',
  info: '#0C87EF',
  infoBg: '#E6F2FE',
  overlay: 'rgba(11,21,38,0.55)',
} as const;

export const gradients = {
  brand: ['#2EE3CC', '#0C87EF'] as const,
  navy: ['#0B1526', '#12305A'] as const,
  soft: ['#E4EEF8', '#CFE4FF'] as const,
};

export const radius = { sm: 10, md: 14, lg: 18, xl: 22, pill: 999 } as const;

export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 20, xxl: 28, xxxl: 40 } as const;

export const fonts = {
  body: 'IBMPlexSansArabic_400Regular',
  bodyMedium: 'IBMPlexSansArabic_500Medium',
  bodyBold: 'IBMPlexSansArabic_700Bold',
  display: 'ReadexPro_600SemiBold',
  displayBold: 'ReadexPro_700Bold',
} as const;

export const shadow = {
  card: {
    shadowColor: '#0B1526',
    shadowOpacity: 0.06,
    shadowRadius: 14,
    shadowOffset: { width: 0, height: 6 },
    elevation: 2,
  },
  raised: {
    shadowColor: '#0C87EF',
    shadowOpacity: 0.22,
    shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 },
    elevation: 6,
  },
} as const;

export type Tone = 'brand' | 'ok' | 'warn' | 'danger' | 'info' | 'violet' | 'amber' | 'blue' | 'mute';

export function toneColors(tone: string | undefined): { fg: string; bg: string } {
  switch (tone) {
    case 'ok':
    case 'success':
      return { fg: colors.success, bg: colors.successBg };
    case 'warn':
    case 'amber':
      return { fg: colors.warn, bg: colors.warnBg };
    case 'danger':
      return { fg: colors.danger, bg: colors.dangerBg };
    case 'violet':
      return { fg: '#6B4FE0', bg: '#EFEBFF' };
    case 'mute':
      return { fg: colors.mute2, bg: colors.bgAlt };
    default:
      return { fg: colors.blue, bg: colors.infoBg };
  }
}
