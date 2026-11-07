/** Response shapes of the Spread AI backend (verified against public/api/v1 and public/api/*.php) */

export type User = {
  id: number;
  name: string;
  email: string;
  phone: string;
  avatar: string | null;
  auth_provider: 'password' | 'google' | 'both';
  two_fa: boolean;
  created_at: string;
  deletion_at: string | null;
  needs_phone: boolean;
};

export type LoginResult =
  | { ok: true; token: string; expires_in: number; user: User; two_factor?: undefined }
  | { ok: true; two_factor: true; challenge: string; expires_in: number; email_hint: string };

export type Option = { key: string; label: string; hint?: string; emoji?: string };

export type Bootstrap = {
  api_version: number;
  app_name: string;
  min_app_version: string;
  purchases: 'off' | 'web';
  site_url: string;
  links: { privacy: string; terms: string; help: string; support: string; reset_password: string };
  options: {
    content_types: Option[];
    platforms: Option[];
    lengths: Option[];
    tones: Option[];
    dialects: Option[];
    templates: { id: number; name: string }[];
    ratios: Option[];
    formats: Option[];
  };
  costs: Record<string, number>;
  signed_in: boolean;
};

export type Route =
  | { kind: 'screen'; screen: string; id: number | null }
  | { kind: 'external'; url: string }
  | { kind: 'notification'; id: number };

export type UsageUnit = {
  key: string;
  emoji: string;
  label: string;
  limit: number;
  open: boolean;
  used: number;
  pct: number | null;
  left: number | null;
};

export type Usage = {
  show_numbers: boolean;
  balance: number;
  used: number;
  total: number;
  pct: number;
  plan: string | null;
  expires_at: string | null;
  days_left: number | null;
  ends_label: string | null;
  units: UsageUnit[];
};

export type Dashboard = {
  greeting: string;
  first_name: string;
  business: string;
  brand_health: { pct: number; gate: number; unlocked: boolean };
  actions: { n: number; label: string; tone: string; route: Route }[];
  journey: { done: number; total: number; pct: number; show: boolean; next: { label: string; route: Route } | null };
  month: { posts: number; designs: number; active_campaigns: number };
  usage: Usage;
  subscribed: boolean;
  recent: { id: number; type: string; type_label: string; excerpt: string; status: string; status_label: string; cover: string | null; created_at: string }[];
  recent_designs: { id: number; url: string | null; ratio: string; created_at: string }[];
  slides: { id: number; title: string; body: string; image: string | null; button: string; route: Route }[];
  badge: number;
};

export type NotificationsResp = {
  badge: number;
  items: { type: string; icon: string; tone: string; title: string; sub: string; route: Route }[];
  history: { id: number; kind: string; title: string; body: string; tone: string; read: boolean; created_at: string; route: Route }[];
};

export type CreditsResp = {
  usage: Usage;
  subscribed: boolean;
  costs: Record<string, number>;
  history: { id: number; type: 'add' | 'consume' | 'deduct'; amount: number | null; label: string; notes: string; created_at: string }[];
  payments: { id: number; status: string; package: string; created_at: string }[];
  purchases: 'off' | 'web';
};

export type Package = {
  id: number;
  name: string;
  description: string;
  badge: string;
  featured: boolean;
  validity_days: number;
  features: string[];
  quotas: Record<string, number>;
  price_egp: number | null;
};

export type ContentStatus = { key: string; label: string; color: string; icon: string; pct: number; next?: string };

/** api/contents.php lib_row */
export type LibraryItem = {
  id: number;
  format: string;
  format_label: string;
  format_emoji: string;
  slides_count: number | null;
  type: string;
  type_label: string;
  platform: string;
  hook: string;
  status: ContentStatus;
  pct?: number;
  missing?: string[];
  next?: { label: string; kind: string; url: string } | null;
  scheduled: string | null;
  fail: string | null;
  campaign: { id: number; title: string } | null;
  cover: string | null;
  ago: string;
};

export type LibraryResp = {
  items: LibraryItem[];
  total: number;
  page: number;
  more: boolean;
  counts: Record<string, number>;
  statuses?: { key: string; label: string }[];
};

export type ContentDetail = LibraryItem & {
  text: string;
  hashtags: string;
  cta: string;
  rev: string;
  published: boolean;
  date: string;
  costs: { ai_edit: number; design: number; balance: number };
  designs: { id: number; url: string; ratio: string; ago: string; current: boolean }[];
  versions: { id: number; n: number; text: string; hashtags: string; cta: string; type: string; note: string; at: string }[];
  brand: { name: string; logo: string | null };
};

export type Paywall = { title: string; body: string; benefits: string[]; cta: string; url: string };

export type GenerateContentResult = { content_id: number; content: string; hashtags: string; cta: string };
export type GenerateDesignResult = { design_id: number; image_url: string; slide_no: number | null; ratio: string };
export type StudioDesignResult = { design_id: number; image_url: string; ratio: string };

export type SocialPage = { id: number; name: string; avatar: string | null; ig: string | null; has_ig: boolean; status: string };

export type SettingsHome = {
  profile: { name: string; email: string; phone: string; avatar: string | null; initials: string; since: string; google: boolean };
  plan: Record<string, unknown> & { name: string; pct: number; expires: string; days_left: number | null };
  accounts: { allowed: boolean; pages: SocialPage[]; max: number; can_add: boolean };
  security: {
    has_password: boolean;
    two_fa: boolean;
    sessions: { id: number; device: string; where: string; current: boolean }[];
    deletion: { date: string; days: number } | null;
  };
};
