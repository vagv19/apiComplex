export interface ApiEnvelope<T> {
  success: boolean

  data: T

  message: string

  errors?: Record<string, string[]>
}

export interface PageResult<T> {
  items: T[]

  page: number

  limit: number

  total: number
}

export interface Category {
  id: number

  name: string

  description: string | null

  is_active: number
}

export interface Team {
  id: number

  name: string

  short_name: string | null

  logo_url: string | null

  contact_name: string | null

  contact_phone: string | null

  is_active: number
}

export interface Player {
  id: number

  first_name: string

  last_name: string

  birth_date: string | null

  photo_url: string | null

  phone: string | null

  email: string | null

  is_active: number
}

export interface Tournament {
  id: number

  name: string

  category_id: number

  format: "LEAGUE" | "CUP" | "FLASH" | string

  status: string

  start_date: string | null

  end_date: string | null

  points_for_win: number

  points_for_draw: number

  points_for_loss: number

  rounds_count: number

  qualified_teams: number

  player_registration_deadline_round: number

  allow_player_registration: number | boolean

  tie_breakers: string | null
}

export interface TournamentTeam {
  id: number

  tournament_id: number

  team_id: number

  name: string

  short_name: string | null

  logo_url: string | null

  tournament_team_id: number

  status: string
}

export interface TournamentPlayer {
  id: number

  tournament_id: number

  tournament_team_id: number

  team_id: number

  player_id: number

  status: string

  first_name: string

  last_name: string

  team_name: string

  jersey_number: number | null
}

export interface MatchReportPlayer {
  tournament_player_id: number

  tournament_team_id: number

  team_id: number

  team_name: string

  first_name: string

  last_name: string

  jersey_number: number | null
}

export interface MatchAttendance {
  tournament_player_id: number

  attendance_status: "PRESENT" | "ABSENT"
}

export interface MatchGoal {
  id?: number

  client_key: string

  tournament_player_id: number

  credited_to_team_id: number

  goal_type: "REGULAR" | "PENALTY" | "OWN_GOAL"

  notes: string | null
}

export interface MatchDisciplinaryEvent {
  id?: number

  client_key: string

  tournament_player_id: number

  event_type: "YELLOW_CARD" | "SECOND_YELLOW_RED" | "DIRECT_RED"

  expulsion_reason_id: number | null

  notes: string | null
}

export interface MatchReport {
  match: {
    id: number

    tournament_id: number

    status: string

    home_team_id: number

    home_tournament_team_id: number

    home_team_name: string

    away_team_id: number

    away_tournament_team_id: number

    away_team_name: string

    home_score: number | null

    away_score: number | null

    home_penalties: number | null

    away_penalties: number | null
  }

  players: MatchReportPlayer[]

  attendance: MatchAttendance[]

  goals: (MatchGoal & { first_name: string; last_name: string })[]

  disciplinary_events: (MatchDisciplinaryEvent & {
    first_name: string
    last_name: string
    expulsion_reason_name: string | null
    suspension_id: number | null
    suspension_reason_name: string | null
    total_matches: number | null
    served_matches: number | null
    remaining_matches: number | null
    suspension_status: "ACTIVE" | "COMPLETED" | "OVERRIDDEN" | null
  })[]

  active_expulsion_reasons: ExpulsionReason[]
}

export interface ExpulsionReason {
  id: number

  name: string

  description: string | null

  suspension_matches: number

  is_active: number

  created_at?: string

  updated_at?: string
}

export interface PlayerSuspension {
  id: number

  disciplinary_event_id: number

  expulsion_reason_name: string

  total_matches: number

  served_matches: number

  remaining_matches: number

  status: "ACTIVE" | "COMPLETED" | "OVERRIDDEN"

  match_id: number

  event_type: string

  match_date: string | null

  tournament_id: number

  tournament_name: string
}

export interface Round {
  id: number

  tournament_id: number

  stage_id: number

  stage_name: string

  stage_type: string

  stage_sequence: number

  group_id: number | null

  group_name: string | null

  round_number: number

  name: string | null

  status: string
}

export interface Match {
  id: number

  round_id: number

  stage_type: string

  group_id: number | null

  group_name: string | null

  match_number: number | null

  home_team_id: number | null

  away_team_id: number | null

  home_tournament_team_id: number | null

  away_tournament_team_id: number | null

  home_score: number | null

  away_score: number | null

  home_penalties: number | null

  away_penalties: number | null

  status: string

  match_date: string | null

  scheduled_at: string | null

  kickoff_time: string | null

  venue: string | null

  is_bye: number
}

export interface MatchDayFixture {
  id: number

  match_date: string

  kickoff_time: string | null

  match_number: number | null

  home_score: number | null

  away_score: number | null

  status: string

  venue: string | null

  round_id: number

  round_number: number

  round_name: string | null

  group_name: string | null

  stage_name: string

  stage_type: string

  stage_sequence: number

  tournament_id: number

  tournament_name: string

  category_id: number

  category_name: string

  home_team_id: number

  home_team_name: string

  away_team_id: number

  away_team_name: string
}

export interface GroupStanding {
  tournament_team_id: number

  team_id: number

  team_name: string

  position: number

  played: number

  won: number

  drawn: number

  lost: number

  goals_for: number

  goals_against: number

  goal_difference: number

  points: number
}

export interface TournamentGroup {
  id: number

  stage_id: number

  name: string

  group_order: number

  teams: {
    tournament_team_id: number

    team_id: number

    name: string

    short_name: string | null

    logo_url: string | null
  }[]

  standings: GroupStanding[]
}

const apiBase =
  import.meta.env.VITE_API_BASE_URL ||
  (import.meta.env.DEV
    ? "/backend-api/api/v1"
    : `${window.location.origin}/apiComplex/backend/public/api/v1`)

const authStorageKey = "sport-complex-admin-token"

export function getAuthToken(): string {
  return sessionStorage.getItem(authStorageKey) ?? ""
}

export function setAuthToken(token: string): void {
  sessionStorage.setItem(authStorageKey, token)
}

export function clearAuthToken(): void {
  sessionStorage.removeItem(authStorageKey)
}

async function request<T>(
  path: string,

  options: RequestInit = {},
): Promise<T> {
  const response = await fetch(`${apiBase}${path}`, {
    ...options,

    headers: {
      Accept: "application/json",

      ...(options.body ? { "Content-Type": "application/json" } : {}),

      ...(path !== "/auth/login" && getAuthToken()
        ? { Authorization: `Bearer ${getAuthToken()}` }
        : {}),

      ...options.headers,
    },
  })

  let envelope: ApiEnvelope<T> | undefined

  try {
    envelope = ((await response.json()) as ApiEnvelope<T>)
  } catch {
    throw new Error(
      `El servidor respondió con un formato inesperado (${response.status}).`,
    )
  }

  if (!response.ok || !envelope.success) {
    if (response.status === 401 && path !== "/auth/login") {
      clearAuthToken()
      window.dispatchEvent(new Event("sport-complex:unauthorized"))
    }
    const details = envelope.errors
      ? Object.values(envelope.errors).flat().join(" ")
      : ""

    throw new Error(
      [envelope.message || "No se pudo completar la solicitud.", details]

        .filter(Boolean)

        .join(" "),
    )
  }

  return envelope.data
}

export const api = {
  get: <T>(path: string) => request<T>(path),

  post: <T>(path: string, body?: unknown) =>
    request<T>(path, {
      method: "POST",

      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    }),

  delete: <T>(path: string) => request<T>(path, { method: "DELETE" }),
}

export async function fetchMatchSheetPdf(matchId: number): Promise<Blob> {
  const endpointBase = apiBase.replace(/\/api\/v1\/?$/, "")
  const path = `${endpointBase}/generar_cedula.php?match_id=${encodeURIComponent(String(matchId))}`
  const response = await fetch(path, {
    headers: {
      Accept: "application/pdf",
      ...(getAuthToken()
        ? { Authorization: `Bearer ${getAuthToken()}` }
        : {}),
    },
  })

  if (!response.ok) {
    if (response.status === 401) {
      clearAuthToken()
      window.dispatchEvent(new Event("sport-complex:unauthorized"))
    }
    let message = "No se pudo generar la cédula arbitral."
    try {
      const error = (await response.json()) as Pick<ApiEnvelope<unknown>, "message">
      if (error.message) message = error.message
    } catch {
      // A non-JSON server error keeps the endpoint's generic message.
    }
    throw new Error(message)
  }

  if (!response.headers.get("Content-Type")?.toLowerCase().includes("application/pdf")) {
    throw new Error("El servidor no devolvió un archivo PDF válido.")
  }

  return response.blob()
}

export function listPath(resource: string): string {
  return `/${resource}?page=1&limit=100`
}
