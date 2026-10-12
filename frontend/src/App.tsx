import {
  useCallback,
  useEffect,
  useMemo,
  useState,
  type FormEvent,
  type ReactNode,
} from "react"

import {
  api,
  clearAuthToken,
  fetchMatchSheetPdf,
  getAuthToken,
  listPath,
  setAuthToken,
  type Category,
  type ExpulsionReason,
  type MatchAttendance,
  type MatchDisciplinaryEvent,
  type MatchGoal,
  type Match,
  type MatchReport,
  type PageResult,
  type Player,
  type Round,
  type Team,
  type Tournament,
  type TournamentGroup,
  type TournamentPlayer,
  type TournamentTeam,
} from "./lib/api"
import ExpulsionReasonsPage from "./components/ExpulsionReasonsPage"
import MatchDayPage from "./components/MatchDayPage"
import MatchPlayerCapture from "./components/MatchPlayerCapture"
import TournamentTeamRegistrationDialog from "./components/TournamentTeamRegistrationDialog"

type Page = "dashboard" | "tournaments" | "categories" | "teams" | "players" | "rosters" | "schedule" | "matchday" | "results" | "standings" | "discipline"

type EntityKind = "category" | "team" | "player" | "tournament" | "register-player"

type DialogState = {
  kind: EntityKind;
  record?: Record<string, unknown>;
} | null;

type FormValues = Record<string, string>

const navItems: { id: Page; label: string; icon: string }[] = [
  { id: "dashboard", label: "Resumen", icon: "⌂" },

  { id: "tournaments", label: "Torneos", icon: "♜" },

  { id: "categories", label: "Categorías", icon: "◈" },

  { id: "teams", label: "Equipos", icon: "⚑" },

  { id: "players", label: "Jugadores", icon: "♙" },

  { id: "rosters", label: "Plantillas", icon: "♟" },

  { id: "schedule", label: "Calendario", icon: "▦" },

  { id: "matchday", label: "Partidos del día", icon: "◷" },

  { id: "results", label: "Resultados", icon: "◷" },

  { id: "standings", label: "Posiciones", icon: "☷" },

  { id: "discipline", label: "Motivos de expulsión", icon: "⚠" },
]

const blankValues: Record<EntityKind, FormValues> = {
  category: { name: "", description: "" },

  team: {
    name: "",
    short_name: "",
    logo_url: "",
    contact_name: "",
    contact_phone: "",
  },

  player: {
    first_name: "",
    last_name: "",
    birth_date: "",
    photo_url: "",
    phone: "",
    email: "",
  },

  tournament: {
    name: "",

    category_id: "",

    format: "LEAGUE",

    status: "REGISTRATION",

    start_date: "",

    end_date: "",

    points_for_win: "3",

    points_for_draw: "1",

    points_for_loss: "0",

    rounds_count: "10",

    qualified_teams: "8",

    player_registration_deadline_round: "4",
  },

  "register-player": { player_id: "", team_id: "" },
}

function getInitialValues(
  kind: EntityKind,

  record?: Record<string, unknown>,
): FormValues {
  return {
    ...blankValues[kind],

    ...Object.fromEntries(
      Object.keys(blankValues[kind]).map((key) => [
        key,

        record?.[key] == null ? "" : String(record[key]),
      ]),
    ),
  }
}

function displayDate(value: string | null | undefined): string {
  if (!value) return "Sin fecha"

  const date = new Date(`${value.slice(0, 10)}T00:00:00`)

  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat("es-MX", {
        day: "2-digit",

        month: "short",

        year: "numeric",
      }).format(date)
}

function formatName(format: string): string {
  return { LEAGUE: "Liga", CUP: "Copa", FLASH: "Relámpago" }[format] ?? format
}

function statusName(status: string): string {
  return (
    {
      DRAFT: "Borrador",

      REGISTRATION: "Inscripciones",

      IN_PROGRESS: "En curso",

      COMPLETED: "Finalizado",

      CANCELLED: "Cancelado",

      SCHEDULED: "Programado",

      COMPLETED_MATCH: "Finalizado",

      BYE: "Descanso",

      PLANNED: "Planeada",

      PENDING: "Pendiente",
    }[status] ?? status
  )
}

function shortName(name: string): string {
  return name

    .split(/\s+/)

    .filter(Boolean)

    .slice(0, 2)

    .map((part) => part[0].toUpperCase())

    .join("")
}

function ApiError({ message }: { message: string }) {
  return (
    <div className="app-alert" role="alert">
      <strong>No se pudo completar la operación</strong>
      <span>{message}</span>
    </div>
  )
}

function EmptyState({
  title,

  detail,

  action,
}: {
  title: string

  detail: string

  action?: ReactNode
}) {
  return (
    <div className="app-empty">
      <span className="app-empty-mark">⚽</span>
      <strong>{title}</strong>
      <p>{detail}</p>
      {action}
    </div>
  )
}

function ActionButton({
  children,

  onClick,

  variant = "primary",

  disabled,

  type = "button",
}: {
  children: ReactNode

  onClick?: () => void

  variant?: "primary" | "secondary" | "danger"

  disabled?: boolean

  type?: "button" | "submit"
}) {
  return (
    <button
      className={`app-button ${variant}`}
      disabled={disabled}
      onClick={onClick}
      type={type}
    >
      {children}
    </button>
  )
}

function Panel({
  title,

  description,

  action,

  children,
}: {
  title: string

  description?: string

  action?: ReactNode

  children: ReactNode
}) {
  return (
    <section className="app-panel">
      <header className="app-panel-head">
        <div>
          <h2>{title}</h2>
          {description && <p>{description}</p>}
        </div>
        {action}
      </header>
      {children}
    </section>
  )
}

function PageHeading({
  kicker,

  title,

  description,

  action,
}: {
  kicker: string

  title: string

  description: string

  action?: React.ReactNode
}) {
  return (
    <div className="app-page-heading">
      <div>
        <span className="app-kicker">{kicker}</span>
        <h1>{title}</h1>
        <p>{description}</p>
      </div>
      {action}
    </div>
  )
}

function MatchScheduleForm({
  tournamentName,
  categoryName,
  stageLabel,
  homeName,
  awayName,
  match,
  saving,
  onCancel,
  onSave,
}: {
  tournamentName: string
  categoryName: string
  stageLabel: string
  homeName: string
  awayName: string
  match: Match
  saving: boolean
  onCancel: () => void
  onSave: (matchDate: string, kickoffTime: string) => Promise<void>
}) {
  const [matchDate, setMatchDate] = useState(match.match_date?.slice(0, 10) ?? "")
  const [kickoffTime, setKickoffTime] = useState(
    match.kickoff_time?.slice(0, 5) ?? "",
  )
  const [formError, setFormError] = useState("")

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setFormError("")
    try {
      await onSave(matchDate, kickoffTime)
    } catch (caught) {
      setFormError(
        caught instanceof Error
          ? caught.message
          : "No se pudo guardar la programación.",
      )
    }
  }

  return (
    <form className="app-schedule-form" onSubmit={submit}>
      <div className="app-schedule-form-heading">
        <div>
          <span className="app-kicker">PROGRAMACIÓN DE ENCUENTRO</span>
          <h3>{match.match_date ? "Reprogramar encuentro" : "Programar encuentro"}</h3>
          <p>Los datos deportivos se toman del partido y no se pueden modificar aquí.</p>
        </div>
      </div>
      <div className="app-schedule-details">
        <div><span>Torneo</span><strong>{tournamentName}</strong></div>
        <div><span>Categoría</span><strong>{categoryName}</strong></div>
        <div><span>Jornada o etapa</span><strong>{stageLabel}</strong></div>
        <div><span>Encuentro</span><strong>{homeName} vs. {awayName}</strong></div>
      </div>
      <div className="app-schedule-fields">
        <label className="app-field">
          <span>Fecha programada</span>
          <input
            type="date"
            required
            value={matchDate}
            onChange={(event) => setMatchDate(event.target.value)}
          />
        </label>
        <label className="app-field">
          <span>Hora programada</span>
          <input
            type="time"
            required
            value={kickoffTime}
            onChange={(event) => setKickoffTime(event.target.value)}
          />
        </label>
      </div>
      {formError && <div className="app-inline-error" role="alert">{formError}</div>}
      <footer className="app-schedule-actions">
        <ActionButton variant="secondary" onClick={onCancel} disabled={saving}>
          Cancelar
        </ActionButton>
        <ActionButton type="submit" disabled={saving}>
          {saving ? "Guardando..." : "Guardar programación"}
        </ActionButton>
      </footer>
    </form>
  )
}

function SearchField({
  value,

  onChange,

  placeholder,
}: {
  value: string

  onChange: (value: string) => void

  placeholder: string
}) {
  return (
    <label className="app-search">
      <span aria-hidden="true">⌕</span>
      <input
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
      />
    </label>
  )
}

function ResourceDialog({
  kind,

  record,

  categories,

  players,

  registeredTeams,

  saving,

  onClose,

  onSave,
}: {
  kind: EntityKind

  record?: Record<string, unknown>

  categories: Category[]

  players: Player[]

  registeredTeams: TournamentTeam[]

  saving: boolean

  onClose: () => void

  onSave: (values: FormValues) => Promise<void>
}) {
  const [values, setValues] = useState(() => getInitialValues(kind, record))

  const [error, setError] = useState("")

  const edit = Boolean(record)

  const titles: Record<EntityKind, string> = {
    category: edit ? "Editar categoría" : "Nueva categoría",

    team: edit ? "Editar equipo" : "Registrar equipo",

    player: edit ? "Editar jugador" : "Registrar jugador",

    tournament: "Crear torneo",

    "register-player": "Inscribir jugador",
  }

  const set = (key: string, value: string) =>
    setValues((current) => ({ ...current, [key]: value }))

  const field = (
    key: string,

    label: string,

    required = false,

    type = "text",
  ) => (
    <label className="app-field" key={key}>
      <span>{label}</span>
      <input
        type={type}
        required={required}
        value={values[key] ?? ""}
        onChange={(event) => set(key, event.target.value)}
      />
    </label>
  )

  const select = (
    key: string,

    label: string,

    options: { value: string; label: string }[],

    required = false,
  ) => (
    <label className="app-field" key={key}>
      <span>{label}</span>
      <select
        required={required}
        value={values[key] ?? ""}
        onChange={(event) => set(key, event.target.value)}
      >
        <option value="">Selecciona...</option>
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </label>
  )

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    setError("")

    try {
      await onSave(values)
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "Ocurrió un error al guardar.",
      )
    }
  }

  return (
    <div className="app-modal-backdrop" onMouseDown={onClose}>
      <form
        className="app-modal"
        onSubmit={handleSubmit}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="app-modal-head">
          <div>
            <span className="app-kicker">SPORT COMPLEX LA PASIÓN</span>
            <h2>{titles[kind]}</h2>
          </div>
          <button
            className="app-close"
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
          >
            ×
          </button>
        </div>
        <div className="app-form-grid">
          {kind === "category" && (
            <>
              {field("name", "Nombre", true)}
              {field("description", "Descripción")}
            </>
          )}
          {kind === "team" && (
            <>
              {field("name", "Nombre del equipo", true)}
              {field("short_name", "Abreviatura")}
              {field("logo_url", "URL del escudo")}
              {field("contact_name", "Nombre de contacto")}
              {field("contact_phone", "Teléfono de contacto")}
            </>
          )}
          {kind === "player" && (
            <>
              {field("first_name", "Nombre", true)}
              {field("last_name", "Apellidos", true)}
              {field("birth_date", "Fecha de nacimiento", false, "date")}
              {field("photo_url", "URL de la fotografía")}
              {field("phone", "Teléfono")}
              {field("email", "Correo electrónico", false, "email")}
            </>
          )}
          {kind === "tournament" && (
            <>
              {field("name", "Nombre del torneo", true)}
              {select(
                "category_id",

                "Categoría",

                categories.map((category) => ({
                  value: String(category.id),

                  label: category.name,
                })),
                true,
              )}
              {select(
                "format",
                "Formato",
                [
                  { value: "LEAGUE", label: "Liga" },

                  { value: "CUP", label: "Copa" },

                  { value: "FLASH", label: "Relámpago" },
                ],
                true,
              )}
              {values.format !== "LEAGUE" && (
                <div className="app-alert">
                  <strong>Funcionalidad parcial</strong>
                  <span>
                    La generación de jornadas y posiciones está disponible
                    únicamente para Liga. Copa y Relámpago permiten registrar
                    el torneo y sus equipos, pero no generar grupos ni cuadros.
                  </span>
                </div>
              )}
              {select(
                "status",
                "Estado inicial",
                [
                  { value: "DRAFT", label: "Borrador" },

                  { value: "REGISTRATION", label: "Inscripciones" },
                ],
                true,
              )}
              {field("start_date", "Fecha de inicio", false, "date")}
              {field("end_date", "Fecha de cierre", false, "date")}
              {field("rounds_count", "Jornadas regulares", true, "number")}
              {field("qualified_teams", "Equipos clasificados", true, "number")}
              {field(
                "player_registration_deadline_round",
                "Altas hasta jornada",
                true,
                "number",
              )}
              {field("points_for_win", "Puntos por victoria", true, "number")}
              {field("points_for_draw", "Puntos por empate", true, "number")}
              {field("points_for_loss", "Puntos por derrota", true, "number")}
            </>
          )}
          {kind === "register-player" && (
            <>
              {select(
                "player_id",

                "Jugador del banco",

                players.map((player) => ({
                  value: String(player.id),

                  label: `${player.first_name} ${player.last_name}`,
                })),

                true,
              )}
              {select(
                "team_id",

                "Equipo inscrito",

                registeredTeams.map((team) => ({
                  value: String(team.team_id || team.id),

                  label: team.name,
                })),

                true,
              )}
            </>
          )}
        </div>
        {error && <div className="app-inline-error">{error}</div>}
        <footer className="app-modal-actions">
          <ActionButton variant="secondary" onClick={onClose}>
            Cancelar
          </ActionButton>
          <ActionButton type="submit" disabled={saving}>
            {saving ? "Guardando..." : edit ? "Guardar cambios" : "Guardar"}
          </ActionButton>
        </footer>
      </form>
    </div>
  )
}

function MatchResultRow({
  match,

  homeName,

  awayName,

  isKnockout,

  onSave,
  isEditor,
  onOpenEditor,
  onCancel,
}: {
  match: Match

  homeName: string

  awayName: string

  isKnockout: boolean
  isEditor: boolean
  onOpenEditor: () => void
  onCancel: () => void

  onSave: (
    match: Match,
    report: {
      home_score: number
      away_score: number
      home_penalties: number | null
      away_penalties: number | null
      attendance?: MatchAttendance[]
      goals?: MatchGoal[]
      disciplinary_events?: MatchDisciplinaryEvent[]
    },
  ) => Promise<void>
}) {
  const [homeScore, setHomeScore] = useState(String(match.home_score ?? ""))

  const [awayScore, setAwayScore] = useState(String(match.away_score ?? ""))

  const [homePenalties, setHomePenalties] = useState(
    String(match.home_penalties ?? ""),
  )

  const [awayPenalties, setAwayPenalties] = useState(
    String(match.away_penalties ?? ""),
  )

  const [saving, setSaving] = useState(false)

  const [error, setError] = useState("")

  const [detailsOpen, setDetailsOpen] = useState(false)

  const [detailsLoading, setDetailsLoading] = useState(false)

  const [report, setReport] = useState<MatchReport | null>(null)

  const [attendance, setAttendance] = useState<Record<number, string>>({})

  const [goals, setGoals] = useState<MatchGoal[]>([])

  const [disciplinaryEvents, setDisciplinaryEvents] = useState<
    MatchReport["disciplinary_events"]
  >([])

  const [detailError, setDetailError] = useState("")

  const applyReport = (loaded: MatchReport) => {
    setReport(loaded)
    setHomeScore(String(loaded.match.home_score ?? ""))
    setAwayScore(String(loaded.match.away_score ?? ""))
    setHomePenalties(String(loaded.match.home_penalties ?? ""))
    setAwayPenalties(String(loaded.match.away_penalties ?? ""))
    setAttendance(
      Object.fromEntries(
        loaded.attendance.map((item) => [
          item.tournament_player_id,
          item.attendance_status,
        ]),
      ),
    )
    setGoals(
      loaded.goals.map(({ first_name: _firstName, last_name: _lastName, ...goal }) => ({
        ...goal,
        notes: goal.notes ?? "",
      })),
    )
    setDisciplinaryEvents(
      loaded.disciplinary_events.map((item) => ({
        ...item,
        notes: item.notes ?? "",
      })),
    )
  }

  const loadReport = async (): Promise<MatchReport> => {
    setDetailsLoading(true)
    setDetailError("")
    try {
      const loaded = await api.get<MatchReport>(`/matches/${match.id}/report`)
      applyReport(loaded)
      return loaded
    } catch (caught) {
      const message =
        caught instanceof Error
          ? caught.message
          : "No se pudo cargar la cédula del partido."
      setDetailError(message)
      throw caught
    } finally {
      setDetailsLoading(false)
    }
  }

  useEffect(() => {
    if (!isEditor) return
    setDetailsOpen(true)
    void loadReport().catch(() => {})
  }, [isEditor, match.id])

  const submit = async (event: FormEvent) => {
    event.preventDefault()

    setError("")

    setSaving(true)

    try {
      const loaded = report ?? (await loadReport())
      const attendanceSnapshot = report
        ? attendance
        : Object.fromEntries(
            loaded.attendance.map((item) => [
              item.tournament_player_id,
              item.attendance_status,
            ]),
          )
      const goalSnapshot = report
        ? goals
        : loaded.goals.map(
            ({ first_name: _firstName, last_name: _lastName, ...goal }) => ({
              ...goal,
              notes: goal.notes ?? "",
            }),
          )
      const eventSnapshot = report
        ? disciplinaryEvents
        : loaded.disciplinary_events.map((item) => ({
            ...item,
            notes: item.notes ?? "",
          }))
      const reportDetails = loaded.players.length > 0
        ? {
            attendance: loaded.players.map((player) => ({
              tournament_player_id: player.tournament_player_id,
              attendance_status:
                attendanceSnapshot[player.tournament_player_id] === "PRESENT"
                  ? "PRESENT" as const
                  : "ABSENT" as const,
            })),
            goals: goalSnapshot.filter((goal) => goal.tournament_player_id > 0),
            disciplinary_events: eventSnapshot
              .filter((item) => item.tournament_player_id > 0)
              .map(
                ({
                  id: _id,
                  first_name: _firstName,
                  last_name: _lastName,
                  expulsion_reason_name: _reasonName,
                  suspension_id: _suspensionId,
                  suspension_reason_name: _suspensionReason,
                  total_matches: _total,
                  served_matches: _served,
                  remaining_matches: _remaining,
                  suspension_status: _status,
                  ...item
                }) => item,
              ),
          }
        : {}
      await onSave(
        match,
        {
          home_score: Number(homeScore),
          away_score: Number(awayScore),
          home_penalties: homePenalties === "" ? null : Number(homePenalties),
          away_penalties: awayPenalties === "" ? null : Number(awayPenalties),
          ...reportDetails,
        },
      )
      onCancel()
    } catch (caught) {
      setError(
        caught instanceof Error ? caught.message : "No se pudo guardar.",
      )
    } finally {
      setSaving(false)
    }
  }

  const setPlayerGoalCount = (
    player: MatchReport["players"][number],
    count: number,
  ) => {
    const requestedCount = Math.floor(count)
    setGoals((current) => {
      const playerGoals = current.filter(
        (goal) => goal.tournament_player_id === player.tournament_player_id,
      )
      const retainedGoals = playerGoals.slice(0, requestedCount)
      const newGoals: MatchGoal[] = Array.from(
        { length: Math.max(0, requestedCount - retainedGoals.length) },
        () => ({
          client_key: crypto.randomUUID(),
          tournament_player_id: player.tournament_player_id,
          credited_to_team_id: player.tournament_team_id,
          goal_type: "REGULAR",
          notes: null,
        }),
      )
      return [
        ...current.filter(
          (goal) => goal.tournament_player_id !== player.tournament_player_id,
        ),
        ...retainedGoals,
        ...newGoals,
      ]
    })
  }

  const addDisciplinaryEvent = (
    player: MatchReport["players"][number],
    eventType: MatchDisciplinaryEvent["event_type"],
  ) => {
    setDisciplinaryEvents((current) => [
      ...current,
      {
        client_key: crypto.randomUUID(),
        tournament_player_id: player.tournament_player_id,
        event_type: eventType,
        expulsion_reason_id: null,
        notes: "",
        first_name: player.first_name,
        last_name: player.last_name,
        expulsion_reason_name: null,
        suspension_id: null,
        suspension_reason_name: null,
        total_matches: null,
        served_matches: null,
        remaining_matches: null,
        suspension_status: null,
      },
    ])
  }

  const homeGoalsRecorded = goals.filter(
    (goal) => goal.credited_to_team_id === match.home_tournament_team_id,
  ).length
  const awayGoalsRecorded = goals.filter(
    (goal) => goal.credited_to_team_id === match.away_tournament_team_id,
  ).length
  const goalCountMismatch =
    Boolean(report?.players.length) &&
    homeScore !== "" &&
    awayScore !== "" &&
    (Number(homeScore) !== homeGoalsRecorded ||
      Number(awayScore) !== awayGoalsRecorded)

  if (!isEditor) {
    return (
      <article className="app-result-match-card">
        <div className="app-match-status">{statusName(match.status)}</div>
        <div className="app-result-card-teams">
          <div className="app-result-card-team">
            <span className="app-crest">{shortName(homeName)}</span>
            <strong>{homeName}</strong>
            <span className="app-result-card-score">
              {match.home_score ?? "—"}
            </span>
          </div>
          <span className="app-vs">VS</span>
          <div className="app-result-card-team away">
            <span className="app-result-card-score">
              {match.away_score ?? "—"}
            </span>
            <strong>{awayName}</strong>
            <span className="app-crest">{shortName(awayName)}</span>
          </div>
        </div>
        <ActionButton onClick={onOpenEditor} disabled={Boolean(match.is_bye)}>
          {match.home_score == null
            ? "Capturar resultado"
            : "Editar resultado"}
        </ActionButton>
      </article>
    )
  }

  return (
    <section className="app-result-editor">
      <form className="app-match-result" onSubmit={submit}>
        <div className="app-match-status">{statusName(match.status)}</div>
        <div className="app-score-side">
          <span className="app-crest">{shortName(homeName)}</span>
          <strong>{homeName}</strong>
          <input
            aria-label={`Goles de ${homeName}`}
            type="number"
            min="0"
            required
            value={homeScore}
            onChange={(event) => setHomeScore(event.target.value)}
            disabled={Boolean(match.is_bye)}
          />
        </div>
        <span className="app-vs">VS</span>
        <div className="app-score-side away">
          <input
            aria-label={`Goles de ${awayName}`}
            type="number"
            min="0"
            required
            value={awayScore}
            onChange={(event) => setAwayScore(event.target.value)}
            disabled={Boolean(match.is_bye)}
          />
          <strong>{awayName}</strong>
          <span className="app-crest">{shortName(awayName)}</span>
        </div>
        {isKnockout && homeScore !== "" && homeScore === awayScore && (
          <div className="app-penalty-fields">
            <label>
              Penales {homeName}
              <input
                aria-label={`Penales de ${homeName}`}
                type="number"
                min="0"
                required
                value={homePenalties}
                onChange={(event) => setHomePenalties(event.target.value)}
              />
            </label>
            <label>
              Penales {awayName}
              <input
                aria-label={`Penales de ${awayName}`}
                type="number"
                min="0"
                required
                value={awayPenalties}
                onChange={(event) => setAwayPenalties(event.target.value)}
              />
            </label>
          </div>
        )}
        <div className="app-match-save">
          {error && <span className="app-inline-error">{error}</span>}
          {detailsLoading && <span className="app-muted">Cargando plantillas...</span>}
        </div>
      
      {!match.is_bye && (
        <div className="app-match-report-details">
          {detailsOpen && detailError && (
            <div className="app-inline-error">{detailError}</div>
          )}
          {detailsOpen && report && (
            <>
            <div className="app-entry-form-heading">
              <div>
                <span className="app-kicker">CÉDULA DEL PARTIDO</span>
                <h3>Plantilla, asistencia y disciplina</h3>
                <p>Registra el marcador y los eventos por jugador. Los dos equipos se guardan juntos.</p>
              </div>
              <span className="app-status">{report.players.length} jugadores</span>
            </div>
            {isKnockout && homeScore !== "" && homeScore === awayScore && (
              <div className="app-entry-penalties">
                <strong>Desempate por penales</strong>
                <label>
                  Penales {homeName}
                  <input
                    aria-label={`Penales de ${homeName}`}
                    type="number"
                    min="0"
                    required
                    value={homePenalties}
                    onChange={(event) => setHomePenalties(event.target.value)}
                  />
                </label>
                <label>
                  Penales {awayName}
                  <input
                    aria-label={`Penales de ${awayName}`}
                    type="number"
                    min="0"
                    required
                    value={awayPenalties}
                    onChange={(event) => setAwayPenalties(event.target.value)}
                  />
                </label>
              </div>
            )}
            <div className="app-report-team-grid">
              {[
                {
                  id: report.match.home_tournament_team_id,
                  name: report.match.home_team_name,
                  side: "home" as const,
                },
                {
                  id: report.match.away_tournament_team_id,
                  name: report.match.away_team_name,
                  side: "away" as const,
                },
              ].map((team) => {
                const roster = report.players.filter(
                  (player) => player.tournament_team_id === team.id,
                )
                const teamGoals = goals.filter((goal) =>
                  roster.some(
                    (player) => player.tournament_player_id === goal.tournament_player_id,
                  ),
                )
                const creditedGoals = goals.filter(
                  (goal) => goal.credited_to_team_id === team.id,
                ).length
                return (
                  <section
                    className={`app-report-team app-report-team-${team.side}`}
                    key={team.id}
                  >
                    <header className="app-report-team-head">
                      <div className="app-crest">{shortName(team.name)}</div>
                      <div>
                        <h3>{team.name}</h3>
                        <p>{roster.length} jugadores en plantilla</p>
                      </div>
                      <span className="app-report-team-count">{creditedGoals} goles</span>
                    </header>
                    {roster.length ? (
                      <div className="app-report-roster">
                        {roster.map((player) => (
                          <MatchPlayerCapture
                            key={player.tournament_player_id}
                            player={player}
                            attended={attendance[player.tournament_player_id] === "PRESENT"}
                            goals={teamGoals.filter(
                              (goal) =>
                                goal.tournament_player_id === player.tournament_player_id,
                            )}
                            events={disciplinaryEvents.filter(
                              (item) =>
                                item.tournament_player_id === player.tournament_player_id,
                            )}
                            reasons={report.active_expulsion_reasons}
                            onAttendanceChange={(playerId, status) =>
                              setAttendance((current) => ({
                                ...current,
                                [playerId]: status ? "PRESENT" : "ABSENT",
                              }))
                            }
                            onGoalCountChange={setPlayerGoalCount}
                            onEventAdd={addDisciplinaryEvent}
                            onEventChange={(updatedEvent) =>
                              setDisciplinaryEvents((current) =>
                                current.map((item) =>
                                  item.client_key === updatedEvent.client_key
                                    ? updatedEvent
                                    : item,
                                ),
                              )
                            }
                            onEventRemove={(clientKey) =>
                              setDisciplinaryEvents((current) =>
                                current.filter((item) => item.client_key !== clientKey),
                              )
                            }
                          />
                        ))}
                      </div>
                    ) : (
                      <div className="app-roster-empty">
                        <strong>Sin jugadores en plantilla</strong>
                        <p>Registra la plantilla del equipo en este torneo para capturar los detalles.</p>
                      </div>
                    )}
                  </section>
                )
              })}
            </div>
            <fieldset className="app-report-sections" disabled hidden>
              <section>
                <h3>Asistencia</h3>
                <div className="app-report-player-list">
                  {report.players.map((player) => (
                    <label className="app-report-player" key={player.tournament_player_id}>
                      <span>
                        {player.team_name} ·{" "}
                        {player.jersey_number == null
                          ? ""
                          : `#${player.jersey_number} `}
                        {player.first_name} {player.last_name}
                      </span>
                      <select
                        aria-label={`Asistencia de ${player.first_name} ${player.last_name}`}
                        value={attendance[player.tournament_player_id] ?? ""}
                        onChange={(change) =>
                          setAttendance((current) => ({
                            ...current,
                            [player.tournament_player_id]: change.target.value,
                          }))
                        }
                      >
                        <option value="">Sin registrar</option>
                        <option value="PRESENT">Presente</option>
                        <option value="ABSENT">Ausente</option>
                      </select>
                    </label>
                  ))}
                  {report.players.length === 0 && (
                    <p className="app-muted">No hay jugadores activos inscritos para los equipos de este partido.</p>
                  )}
                </div>
              </section>
              <section>
                <div className="app-report-section-head">
                  <h3>Goles</h3>
                  <ActionButton
                    variant="secondary"
                    onClick={() =>
                      setGoals((current) => [
                        ...current,
                        {
                          client_key: crypto.randomUUID(),
                          tournament_player_id: 0,
                          credited_to_team_id: report.match.home_tournament_team_id,
                          goal_type: "REGULAR",
                          notes: "",
                        },
                      ])
                    }
                  >
                    + Agregar gol
                  </ActionButton>
                </div>
                {goals.map((goal, index) => (
                  <div className="app-report-row" key={goal.client_key}>
                    <label className="app-field">
                      <span>Jugador</span>
                      <select
                        value={goal.tournament_player_id || ""}
                        onChange={(change) =>
                          setGoals((current) =>
                            current.map((item, itemIndex) =>
                              itemIndex === index
                                ? { ...item, tournament_player_id: Number(change.target.value) }
                                : item,
                            ),
                          )
                        }
                      >
                        <option value="">Seleccionar</option>
                        {report.players.map((player) => (
                          <option key={player.tournament_player_id} value={player.tournament_player_id}>
                            {player.team_name} · {player.first_name} {player.last_name}
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="app-field">
                      <span>Equipo acreditado</span>
                      <select
                        value={goal.credited_to_team_id}
                        onChange={(change) =>
                          setGoals((current) =>
                            current.map((item, itemIndex) =>
                              itemIndex === index
                                ? { ...item, credited_to_team_id: Number(change.target.value) }
                                : item,
                            ),
                          )
                        }
                      >
                        <option value={report.match.home_tournament_team_id}>{report.match.home_team_name}</option>
                        <option value={report.match.away_tournament_team_id}>{report.match.away_team_name}</option>
                      </select>
                    </label>
                    <label className="app-field">
                      <span>Tipo</span>
                      <select
                        value={goal.goal_type}
                        onChange={(change) =>
                          setGoals((current) =>
                            current.map((item, itemIndex) =>
                              itemIndex === index
                                ? { ...item, goal_type: change.target.value as MatchGoal["goal_type"] }
                                : item,
                            ),
                          )
                        }
                      >
                        <option value="REGULAR">Normal</option>
                        <option value="PENALTY">Penal</option>
                        <option value="OWN_GOAL">Autogol</option>
                      </select>
                    </label>
                    <label className="app-field app-report-notes">
                      <span>Observaciones</span>
                      <input
                        value={goal.notes ?? ""}
                        maxLength={300}
                        onChange={(change) =>
                          setGoals((current) =>
                            current.map((item, itemIndex) =>
                              itemIndex === index ? { ...item, notes: change.target.value } : item,
                            ),
                          )
                        }
                      />
                    </label>
                    <ActionButton
                      variant="danger"
                      onClick={() => setGoals((current) => current.filter((_, itemIndex) => itemIndex !== index))}
                    >
                      Quitar
                    </ActionButton>
                  </div>
                ))}
                {goals.length === 0 && <p className="app-muted">No se han registrado goles individuales.</p>}
              </section>
              <section>
                <div className="app-report-section-head">
                  <h3>Amonestaciones y expulsiones</h3>
                  <ActionButton
                    variant="secondary"
                    onClick={() =>
                      setDisciplinaryEvents((current) => [
                        ...current,
                        {
                          client_key: crypto.randomUUID(),
                          tournament_player_id: 0,
                          event_type: "YELLOW_CARD",
                          expulsion_reason_id: null,
                          notes: "",
                          first_name: "",
                          last_name: "",
                          expulsion_reason_name: null,
                          suspension_id: null,
                          suspension_reason_name: null,
                          total_matches: null,
                          served_matches: null,
                          remaining_matches: null,
                          suspension_status: null,
                        },
                      ])
                    }
                  >
                    + Agregar evento
                  </ActionButton>
                </div>
                {disciplinaryEvents.map((item, index) => {
                  const locked = item.suspension_id != null
                  const listedReason = report.active_expulsion_reasons.some(
                    (reason) => reason.id === item.expulsion_reason_id,
                  )
                  return (
                    <div className="app-report-row" key={item.client_key}>
                      <label className="app-field">
                        <span>Jugador</span>
                        <select
                          value={item.tournament_player_id || ""}
                          disabled={locked}
                          onChange={(change) =>
                            setDisciplinaryEvents((current) =>
                              current.map((event, itemIndex) =>
                                itemIndex === index
                                  ? { ...event, tournament_player_id: Number(change.target.value) }
                                  : event,
                              ),
                            )
                          }
                        >
                          <option value="">Seleccionar</option>
                          {report.players.map((player) => (
                            <option key={player.tournament_player_id} value={player.tournament_player_id}>
                              {player.team_name} · {player.first_name} {player.last_name}
                            </option>
                          ))}
                        </select>
                      </label>
                      <label className="app-field">
                        <span>Evento</span>
                        <select
                          value={item.event_type}
                          disabled={locked}
                          onChange={(change) =>
                            setDisciplinaryEvents((current) =>
                              current.map((event, itemIndex) =>
                                itemIndex === index
                                  ? {
                                      ...event,
                                      event_type: change.target.value as MatchDisciplinaryEvent["event_type"],
                                      expulsion_reason_id: change.target.value === "YELLOW_CARD" ? null : event.expulsion_reason_id,
                                    }
                                  : event,
                              ),
                            )
                          }
                        >
                          <option value="YELLOW_CARD">Tarjeta amarilla</option>
                          <option value="SECOND_YELLOW_RED">Expulsión por segunda amarilla</option>
                          <option value="DIRECT_RED">Tarjeta roja directa</option>
                        </select>
                      </label>
                      {item.event_type !== "YELLOW_CARD" && (
                        <label className="app-field">
                          <span>Motivo de expulsión</span>
                          <select
                            required
                            value={item.expulsion_reason_id ?? ""}
                            disabled={locked}
                            onChange={(change) =>
                              setDisciplinaryEvents((current) =>
                                current.map((event, itemIndex) =>
                                  itemIndex === index
                                    ? { ...event, expulsion_reason_id: Number(change.target.value) || null }
                                    : event,
                                ),
                              )
                            }
                          >
                            <option value="">Seleccionar</option>
                            {!listedReason && item.expulsion_reason_id && (
                              <option value={item.expulsion_reason_id}>
                                {item.expulsion_reason_name ?? "Motivo histórico"}
                              </option>
                            )}
                            {report.active_expulsion_reasons.map((reason: ExpulsionReason) => (
                              <option key={reason.id} value={reason.id}>
                                {reason.name} · {reason.suspension_matches} partidos
                              </option>
                            ))}
                          </select>
                        </label>
                      )}
                      <label className="app-field app-report-notes">
                        <span>Observaciones</span>
                        <input
                          value={item.notes ?? ""}
                          maxLength={500}
                          onChange={(change) =>
                            setDisciplinaryEvents((current) =>
                              current.map((event, itemIndex) =>
                                itemIndex === index ? { ...event, notes: change.target.value } : event,
                              ),
                            )
                          }
                        />
                      </label>
                      {locked && (
                        <span className="app-status">
                          Sanción: {item.remaining_matches} pendientes de {item.total_matches}
                        </span>
                      )}
                      <ActionButton
                        variant="danger"
                        disabled={locked}
                        onClick={() =>
                          setDisciplinaryEvents((current) =>
                            current.filter((_, itemIndex) => itemIndex !== index),
                          )
                        }
                      >
                        Quitar
                      </ActionButton>
                    </div>
                  )
                })}
                {disciplinaryEvents.length === 0 && <p className="app-muted">No se han registrado eventos disciplinarios.</p>}
                <p className="app-muted">La doble amarilla se captura con dos eventos de tarjeta amarilla y un evento de expulsión por segunda amarilla. No se registran minutos.</p>
              </section>
            </fieldset>
            </>
          )}
          {detailsOpen && report && (
            <footer className="app-entry-form-actions">
              <div>
                <p>
                  {report.players.length > 0
                    ? "El marcador, la asistencia, los goles y la disciplina se guardan de forma conjunta."
                    : "No hay jugadores activos inscritos; se guardará únicamente el resultado."}
                </p>
                {goalCountMismatch && (
                  <p className="app-goal-count-warning" role="alert">
                    El marcador debe coincidir con los goles registrados: {homeName}{" "}
                    {homeGoalsRecorded}, {awayName} {awayGoalsRecorded}.
                  </p>
                )}
              </div>
              <ActionButton type="submit" disabled={saving || goalCountMismatch}>
                {saving
                  ? "Guardando..."
                  : report.players.length > 0
                    ? "Guardar marcador y cédula"
                    : "Guardar resultado"}
              </ActionButton>
            </footer>
          )}
        </div>
      )}
      </form>
    </section>
  )
}

export default function App() {
  const [page, setPage] = useState<Page>("dashboard")

  const [authToken, setAuthTokenState] = useState(getAuthToken)

  const [authUser, setAuthUser] = useState("")

  const [loginEmail, setLoginEmail] = useState("")

  const [loginPassword, setLoginPassword] = useState("")

  const [loginError, setLoginError] = useState("")

  const [loginBusy, setLoginBusy] = useState(false)

  const [categories, setCategories] = useState<Category[]>([])

  const [teams, setTeams] = useState<Team[]>([])

  const [players, setPlayers] = useState<Player[]>([])

  const [tournaments, setTournaments] = useState<Tournament[]>([])

  const [selectedTournamentId, setSelectedTournamentId] = useState("")

  const [registeredTeams, setRegisteredTeams] = useState<TournamentTeam[]>([])

  const [registeredPlayers, setRegisteredPlayers] =
    useState<TournamentPlayer[]>([])

  const [rounds, setRounds] = useState<Round[]>([])

  const [matches, setMatches] = useState<Match[]>([])

  const [groups, setGroups] = useState<TournamentGroup[]>([])

  const [roundId, setRoundId] = useState("")

  const [activeResultMatchId, setActiveResultMatchId] = useState<number | null>(null)

  const [activeScheduleMatchId, setActiveScheduleMatchId] = useState<number | null>(null)

  const [teamRegistrationOpen, setTeamRegistrationOpen] = useState(false)

  const [groupCount, setGroupCount] = useState("2")

  const [qualifiersPerGroup, setQualifiersPerGroup] = useState("2")

  const [totalQualifiers, setTotalQualifiers] = useState("8")

  const [dialog, setDialog] = useState<DialogState>(null)

  const [search, setSearch] = useState("")

  const [loading, setLoading] = useState(true)

  const [backendConnected, setBackendConnected] = useState(false)

  const [saving, setSaving] = useState(false)

  const [scheduleBusy, setScheduleBusy] = useState(false)

  const [sheetLoadingMatchId, setSheetLoadingMatchId] = useState<number | null>(null)

  const [error, setError] = useState("")

  const [notice, setNotice] = useState("")

  const [sidebarOpen, setSidebarOpen] = useState(false)

  const selectedTournament = tournaments.find(
    (tournament) => String(tournament.id) === selectedTournamentId,
  )

  const refreshCatalogs = useCallback(async () => {
    setError("")

    setLoading(true)

    try {
      const [categoryPage, teamPage, playerPage, tournamentPage] =
        await Promise.all([
          api.get<PageResult<Category>>(listPath("categories")),

          api.get<PageResult<Team>>(listPath("teams")),

          api.get<PageResult<Player>>(listPath("players")),

          api.get<PageResult<Tournament>>(listPath("tournaments")),
        ])

      setBackendConnected(true)

      setCategories(categoryPage.items)

      setTeams(teamPage.items)

      setPlayers(playerPage.items)

      setTournaments(tournamentPage.items)

      setSelectedTournamentId(
        (current) =>
          current ||
          (tournamentPage.items[0] ? String(tournamentPage.items[0].id) : ""),
      )
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudo conectar con el backend.",
      )
      setBackendConnected(false)
    } finally {
      setLoading(false)
    }
  }, [])

  const refreshTournament = useCallback(async (selectLatestRound = false) => {
    if (!selectedTournamentId) {
      setRegisteredTeams([])

      setRegisteredPlayers([])

      setRounds([])

      setMatches([])

      setGroups([])

      return
    }

    try {
      const [teamRows, playerRows, roundRows, groupRows] = await Promise.all([
        api.get<TournamentTeam[]>(`/tournaments/${selectedTournamentId}/teams`),

        api.get<TournamentPlayer[]>(
          `/tournaments/${selectedTournamentId}/players`,
        ),

        api.get<Round[]>(`/tournaments/${selectedTournamentId}/rounds`),

        selectedTournament?.format === "FLASH"
          ? api.get<TournamentGroup[]>(
              `/tournaments/${selectedTournamentId}/groups`,
            )
          : Promise.resolve([] as TournamentGroup[]),
      ])

      setRegisteredTeams(teamRows)

      setRegisteredPlayers(playerRows)

      setRounds(roundRows)
      const groupsWithStandings = await Promise.all(
        groupRows.map(async (group) => ({
          ...group,
          standings: await api.get<TournamentGroup["standings"]>(
            `/groups/${group.id}/standings`,
          ),
        })),
      )
      setGroups(groupsWithStandings)

      setRoundId((current) =>
        selectLatestRound
          ? String(roundRows.at(-1)?.id ?? "")
          : roundRows.some((round) => String(round.id) === current)
            ? current
            : roundRows[0]
              ? String(roundRows[0].id)
              : "",
      )

      const matchLists = await Promise.all(
        roundRows.map((round) =>
          api.get<Match[]>(`/rounds/${round.id}/matches`),
        ),
      )

      setMatches(matchLists.flat())
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudieron cargar los datos del torneo.",
      )
    }
  }, [selectedTournament?.format, selectedTournamentId])

  useEffect(() => {
    const handleUnauthorized = () => {
      setAuthTokenState("")
      setAuthUser("")
      setError("La sesión expiró. Inicia sesión de nuevo.")
      setLoginError("La sesión expiró. Inicia sesión de nuevo.")
    }
    window.addEventListener("sport-complex:unauthorized", handleUnauthorized)
    return () =>
      window.removeEventListener("sport-complex:unauthorized", handleUnauthorized)
  }, [])

  useEffect(() => {
    if (authToken) {
      void refreshCatalogs()
    } else {
      setLoading(false)
    }
  }, [authToken, refreshCatalogs])

  useEffect(() => {
    if (authToken) {
      void refreshTournament()
    } else {
      setRegisteredTeams([])
      setRegisteredPlayers([])
      setRounds([])
      setMatches([])
      setGroups([])
    }
  }, [authToken, refreshTournament])

  useEffect(() => {
    setTotalQualifiers(String(selectedTournament?.qualified_teams ?? 8))
  }, [selectedTournament?.id, selectedTournament?.qualified_teams])

  const signIn = async (event: FormEvent) => {
    event.preventDefault()
    setLoginError("")
    setLoginBusy(true)
    try {
      const session = await api.post<{
        token: string
        user: { email: string; role: string }
      }>("/auth/login", { email: loginEmail, password: loginPassword })
      if (session.user.role !== "admin") {
        throw new Error("Esta cuenta no tiene permisos de administrador.")
      }
      setAuthToken(session.token)
      setAuthTokenState(session.token)
      setAuthUser(session.user.email)
      setLoginPassword("")
    } catch (caught) {
      setLoginError(
        caught instanceof Error ? caught.message : "No se pudo iniciar sesión.",
      )
    } finally {
      setLoginBusy(false)
    }
  }

  const signOut = () => {
    clearAuthToken()
    setAuthTokenState("")
    setAuthUser("")
    setPage("dashboard")
    setError("")
    setNotice("")
  }

  useEffect(() => {
    if (groups.length === 0) return
    const maximum = groups.length * Number(qualifiersPerGroup)
    setTotalQualifiers((current) =>
      String(Math.max(groups.length, Math.min(Number(current) || groups.length, maximum))),
    )
  }, [groups.length])

  const standings = useMemo(() => {
    const rows = new Map<number, {
      team: TournamentTeam

      played: number

      won: number

      drawn: number

      lost: number

      goalsFor: number

      goalsAgainst: number

      points: number
    }>()

    registeredTeams.forEach((entry) => {
      const teamId = Number(entry.team_id || entry.id)

      rows.set(teamId, {
        team: entry,

        played: 0,

        won: 0,

        drawn: 0,

        lost: 0,

        goalsFor: 0,

        goalsAgainst: 0,

        points: 0,
      })
    })

    matches

      .filter(
        (match) =>
          match.status === "COMPLETED" &&
          (selectedTournament?.format !== "LEAGUE" ||
            match.stage_type === "LEAGUE") &&
          !match.is_bye &&
          match.home_team_id != null &&
          match.away_team_id != null,
      )

      .forEach((match) => {
        const home = rows.get(Number(match.home_team_id))

        const away = rows.get(Number(match.away_team_id))

        if (!home || !away) return

        const homeGoals = Number(match.home_score ?? 0)

        const awayGoals = Number(match.away_score ?? 0)

        home.played += 1

        away.played += 1

        home.goalsFor += homeGoals

        home.goalsAgainst += awayGoals

        away.goalsFor += awayGoals

        away.goalsAgainst += homeGoals

        if (homeGoals > awayGoals) {
          home.won += 1

          home.points += Number(selectedTournament?.points_for_win ?? 3)

          away.lost += 1

          away.points += Number(selectedTournament?.points_for_loss ?? 0)
        } else if (awayGoals > homeGoals) {
          away.won += 1

          away.points += Number(selectedTournament?.points_for_win ?? 3)

          home.lost += 1

          home.points += Number(selectedTournament?.points_for_loss ?? 0)
        } else {
          home.drawn += 1

          away.drawn += 1

          home.points += Number(selectedTournament?.points_for_draw ?? 1)

          away.points += Number(selectedTournament?.points_for_draw ?? 1)
        }
      })

    return [...rows.values()].sort((a, b) => {
      const goalDifference =
        b.goalsFor - b.goalsAgainst - (a.goalsFor - a.goalsAgainst)

      return (
        b.points - a.points ||
        goalDifference ||
        b.goalsFor - a.goalsFor ||
        a.team.name.localeCompare(b.team.name, "es")
      )
    })
  }, [matches, registeredTeams, selectedTournament])

  const openDialog = (kind: EntityKind, record?: object) => {
    setDialog({
      kind,

      record: record as Record<string, unknown> | undefined,
    })
  }

  const saveDialog = async (values: FormValues) => {
    if (!dialog) return

    setSaving(true)

    try {
      const data: Record<string, string | number | null> = { ...values }

      for (const key of [
        "category_id",

        "team_id",

        "points_for_win",

        "points_for_draw",

        "points_for_loss",

        "rounds_count",

        "qualified_teams",

        "player_registration_deadline_round",
      ]) {
        if (data[key] === "") {
          delete data[key]
        } else if (data[key] !== undefined) {
          data[key] = Number(data[key])
        }
      }

      for (const key of ["start_date", "end_date", "birth_date"]) {
        if (data[key] === "") data[key] = null
      }

      if (dialog.kind === "register-player") {
        await api.post(`/tournaments/${selectedTournamentId}/players`, {
          team_id: Number(data.team_id),

          player_id: Number(data.player_id),
        })

        setNotice("El jugador quedó inscrito en la plantilla del torneo.")

        await refreshTournament()
      } else {
        const resource = {
          category: "categories",

          team: "teams",

          player: "players",

          tournament: "tournaments",
        }[dialog.kind]

        const id = dialog.record?.id

        if (id) {
          await api.post(`/${resource}/${id}`, data)

          setNotice("Los cambios se guardaron correctamente.")
        } else {
          await api.post(`/${resource}`, data)

          setNotice("El registro se creó correctamente.")
        }

        await refreshCatalogs()
      }

      setDialog(null)
    } finally {
      setSaving(false)
    }
  }

  const deactivate = async (
    kind: "category" | "team" | "player",

    id: number,

    name: string,
  ) => {
    if (!window.confirm(`¿Desactivar "${name}"?`)) return

    const resource = {
      category: "categories",

      team: "teams",

      player: "players",
    }[kind]

    try {
      await api.delete(`/${resource}/${id}`)

      setNotice("El registro se desactivó.")

      await refreshCatalogs()
    } catch (caught) {
      setError(
        caught instanceof Error ? caught.message : "No se pudo desactivar.",
      )
    }
  }

  const generateSchedule = async () => {
    if (!selectedTournament) return

    if (selectedTournament.format !== "LEAGUE") {
      setError(
        "Usa el módulo de formato para generar grupos de Relámpago o el cuadro de Copa.",
      )

      return
    }

    if (rounds.length > 0) {
      setError(
        "Este torneo ya tiene jornadas. La API actual no admite regenerar el calendario sin duplicar partidos.",
      )

      return
    }

    if (
      !window.confirm(
        "Se generará un calendario de liga para los equipos inscritos. ¿Continuar?",
      )
    )
      return

    setScheduleBusy(true)

    setError("")

    try {
      const result = await api.post<{ rounds: number; matches: number }>(
        `/tournaments/${selectedTournament.id}/schedule/generate`,
      )

      setNotice(
        `Calendario generado: ${result.rounds} jornadas y ${result.matches} partidos.`,
      )

      await refreshTournament()
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudo generar el calendario.",
      )
    } finally {
      setScheduleBusy(false)
    }
  }

  const saveResult = async (

    match: Match,

    report: {
      home_score: number
      away_score: number
      home_penalties: number | null
      away_penalties: number | null
      attendance?: MatchAttendance[]
      goals?: MatchGoal[]
      disciplinary_events?: MatchDisciplinaryEvent[]
    },
  ) => {
    await api.post(`/matches/${match.id}/report`, {
      ...report,
      reason: "Resultado capturado desde el panel de administración.",
    })

    setNotice(
      report.attendance
        ? "Marcador y cédula guardados. Se actualizó la tabla de posiciones."
        : "Resultado guardado. Se actualizó la tabla de posiciones.",
    )

    await refreshTournament()
  }

  const saveMatchSchedule = async (
    match: Match,
    matchDate: string,
    kickoffTime: string,
  ) => {
    setScheduleBusy(true)
    setError("")
    try {
      await api.post(`/matches/${match.id}/schedule`, {
        match_date: matchDate,
        kickoff_time: kickoffTime,
      })
      setNotice("La fecha y hora del encuentro se guardaron correctamente.")
      setActiveScheduleMatchId(null)
      await refreshTournament()
    } finally {
      setScheduleBusy(false)
    }
  }

  const openMatchSheet = async (matchId: number) => {
    const pdfTab = window.open("about:blank", "_blank")
    if (!pdfTab) {
      setError("Permite las ventanas emergentes para abrir la cédula.")
      return
    }

    pdfTab.opener = null
    pdfTab.document.title = "Generando cédula arbitral..."
    setSheetLoadingMatchId(matchId)
    setError("")
    try {
      const pdf = await fetchMatchSheetPdf(matchId)
      const pdfUrl = URL.createObjectURL(pdf)
      pdfTab.location.href = pdfUrl
      window.setTimeout(() => URL.revokeObjectURL(pdfUrl), 60_000)
      setNotice("La cédula arbitral se abrió en una pestaña nueva.")
    } catch (caught) {
      pdfTab.close()
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudo abrir la cédula arbitral.",
      )
    } finally {
      setSheetLoadingMatchId(null)
    }
  }

  const filteredCategories = categories.filter((category) =>
    `${category.name} ${category.description ?? ""}`

      .toLowerCase()

      .includes(search.toLowerCase()),
  )

  const filteredTeams = teams.filter((team) =>
    `${team.name} ${team.short_name ?? ""}`

      .toLowerCase()

      .includes(search.toLowerCase()),
  )

  const filteredPlayers = players.filter((player) =>
    `${player.first_name} ${player.last_name}`

      .toLowerCase()

      .includes(search.toLowerCase()),
  )

  const filteredTournaments = tournaments.filter((tournament) =>
    tournament.name.toLowerCase().includes(search.toLowerCase()),
  )

  const selectedRound = rounds.find((round) => String(round.id) === roundId)

  const roundMatches = matches.filter(
    (match) => String(match.round_id) === roundId,
  )
  const activeResultMatch = roundMatches.find(
    (match) => match.id === activeResultMatchId,
  )
  const activeScheduleMatch = roundMatches.find(
    (match) => match.id === activeScheduleMatchId,
  )

  const groupMatches = matches.filter((match) => match.stage_type === "GROUP_STAGE")

  const groupRounds = rounds.filter((round) => round.stage_type === "GROUP_STAGE")

  const regularLeagueMatches = matches.filter(
    (match) => match.stage_type === "LEAGUE" && !match.is_bye,
  )

  const regularLeagueRounds = rounds.filter(
    (round) => round.stage_type === "LEAGUE",
  )

  const regularLeagueResolved =
    regularLeagueRounds.length > 0 &&
    regularLeagueRounds.every((round) => {
      const roundMatches = matches.filter((match) => match.round_id === round.id)
      return (
        roundMatches.length > 0 &&
        roundMatches.every(
          (match) => Boolean(match.is_bye) || match.status === "COMPLETED",
        )
      )
    }) &&
    regularLeagueMatches.length > 0

  const knockoutRounds = rounds
    .filter((round) => round.stage_type === "KNOCKOUT")
    .sort((left, right) => left.round_number - right.round_number)

  const latestKnockoutRound = knockoutRounds.at(-1)

  const latestKnockoutMatches = latestKnockoutRound
    ? matches.filter((match) => match.round_id === latestKnockoutRound.id)
    : []

  const groupStageResolved =
    groupMatches.length > 0 &&
    groupMatches.every(
      (match) => Boolean(match.is_bye) || match.status === "COMPLETED",
    )

  const knockoutRoundResolved =
    latestKnockoutMatches.length > 0 &&
    latestKnockoutMatches.every(
      (match) => Boolean(match.is_bye) || match.status === "COMPLETED",
    )

  const hasKnockoutBracket = knockoutRounds.length > 0

  const submitFormatAction = async (
    path: string,
    body: Record<string, number> | undefined,
    successMessage: (data: Record<string, unknown>) => string,
  ) => {
    if (!selectedTournament) return
    setScheduleBusy(true)
    setError("")
    try {
      const data = await api.post<Record<string, unknown>>(
        `/tournaments/${selectedTournament.id}/${path}`,
        body,
      )
      setNotice(successMessage(data))
      await refreshTournament(
        path === "knockout/advance" ||
          path === "qualification/generate" ||
          path === "league/qualification/generate",
      )
      if (
        data.completed === true ||
        path === "league/qualification/generate"
      ) {
        await refreshCatalogs()
      }
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudo completar la operación del formato.",
      )
    } finally {
      setScheduleBusy(false)
    }
  }

  const cancelFlashGroups = async () => {
    if (!selectedTournament) return
    if (
      !window.confirm(
        "Se eliminarán los grupos y la asignación de equipos. Las inscripciones del torneo se conservarán. ¿Continuar?",
      )
    )
      return

    setScheduleBusy(true)
    setError("")
    try {
      const result = await api.delete<{ groups_deleted: number }>(
        `/tournaments/${selectedTournament.id}/groups`,
      )
      setNotice(
        result.groups_deleted > 0
          ? "Se canceló la configuración y se eliminaron los grupos."
          : "No había grupos que cancelar.",
      )
      await refreshTournament()
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudieron cancelar los grupos.",
      )
    } finally {
      setScheduleBusy(false)
    }
  }

  const registeredTeamIds = new Set(
    registeredTeams.map((team) => Number(team.team_id || team.id)),
  )

  const canRegisterTournamentTeams =
    Boolean(selectedTournament) &&
    ["DRAFT", "REGISTRATION"].includes(selectedTournament?.status ?? "") &&
    rounds.length === 0 &&
    groups.length === 0

  const registerTournamentTeams = async (teamIds: number[]) => {
    if (!selectedTournament || !canRegisterTournamentTeams) {
      throw new Error("La inscripción de equipos está cerrada para este torneo.")
    }
    setSaving(true)
    let registeredCount = 0
    try {
      for (const teamId of teamIds) {
        try {
          await api.post(`/tournaments/${selectedTournament.id}/teams`, {
            team_id: teamId,
          })
          registeredCount++
        } catch (caught) {
          await refreshTournament()
          const reason =
            caught instanceof Error
              ? caught.message
              : "Error al inscribir el equipo."
          throw new Error(
            registeredCount > 0
              ? `Se inscribieron ${registeredCount} equipo(s), pero el proceso se detuvo: ${reason} Puedes reintentar los equipos restantes mientras el torneo siga abierto.`
              : reason,
          )
        }
      }
      await refreshTournament()
      setNotice(
        `${registeredCount} equipo(s) inscrito(s) en ${selectedTournament.name}.`,
      )
      setTeamRegistrationOpen(false)
    } finally {
      setSaving(false)
    }
  }

  const teamName = (id: number | null) =>
    registeredTeams.find(
      (team) => Number(team.team_id || team.id) === Number(id),
    )?.name ??
    teams.find((team) => team.id === Number(id))?.name ??
    "Equipo por definir"

  const categoryName = (id: number | null) =>
    categories.find((category) => category.id === Number(id))?.name ??
    "Sin categoría"

  const selectTournament = (tournamentId: string) => {
    setSelectedTournamentId(tournamentId)
    setActiveResultMatchId(null)
    setActiveScheduleMatchId(null)
    setTeamRegistrationOpen(false)
  }

  const go = (nextPage: Page) => {
    setPage(nextPage)
    setActiveResultMatchId(null)
    setActiveScheduleMatchId(null)
    setTeamRegistrationOpen(false)

    setSearch("")

    setSidebarOpen(false)

    setError("")

    setNotice("")
  }

  let content: ReactNode

  if (page === "matchday") {
    content = <MatchDayPage />
  } else if (page === "dashboard") {
    content = (
      <>
        <PageHeading
          kicker="CENTRO DE OPERACIONES"
          title="Resumen general"
          description="Administra las competencias y consulta la actividad de Sport Complex La Pasión."
          action={
            <ActionButton onClick={() => openDialog("tournament")}>
              + Crear torneo
            </ActionButton>
          }
        />
        <div className="app-stat-grid">
          {[
            ["Torneos", tournaments.length, "♜"],

            ["Categorías", categories.length, "◈"],

            ["Equipos", teams.length, "⚑"],

            ["Jugadores", players.length, "♙"],
          ].map(([label, value, icon]) => (
            <div className="app-stat" key={String(label)}>
              <span>{icon}</span>
              <small>{label}</small>
              <strong>{loading ? "…" : value}</strong>
            </div>
          ))}
        </div>
        <div className="app-home-grid">
          <Panel
            title="Torneos recientes"
            description="Competencias registradas en el sistema."
            action={
              <button className="app-link" onClick={() => go("tournaments")}>
                Ver todos →
              </button>
            }
          >
            {tournaments.slice(0, 5).map((tournament) => (
              <button
                className="app-list-row"
                key={tournament.id}
                onClick={() => {
                  setSelectedTournamentId(String(tournament.id))

                  go("schedule")
                }}
              >
                <span className="app-crest">{shortName(tournament.name)}</span>
                <span className="app-list-main">
                  <strong>{tournament.name}</strong>
                  <small>
                    {formatName(tournament.format)} ·{" "}
                    {categoryName(tournament.category_id)}
                  </small>
                </span>
                <span
                  className={`app-status ${tournament.status.toLowerCase()}`}
                >
                  {statusName(tournament.status)}
                </span>
              </button>
            ))}
            {!loading && tournaments.length === 0 && (
              <EmptyState
                title="Aún no hay torneos"
                detail="Crea una competencia para comenzar a organizar partidos."
                action={
                  <ActionButton onClick={() => openDialog("tournament")}>
                    Crear torneo
                  </ActionButton>
                }
              />
            )}
          </Panel>
          <Panel
            title="Accesos rápidos"
            description="Tareas frecuentes de administración."
          >
            <div className="app-quick-links">
              {[
                ["Crear categoría", "categories", "◈"],

                ["Registrar equipo", "teams", "⚑"],

                ["Registrar jugador", "players", "♙"],

                ["Ver calendario", "schedule", "▦"],
              ].map(([label, target, icon]) => (
                <button
                  key={target}
                  onClick={() => {
                    if (target === "categories") openDialog("category")
                    else if (target === "teams") openDialog("team")
                    else if (target === "players") openDialog("player")
                    else go("schedule")
                  }}
                >
                  <span>{icon}</span>
                  <strong>{label}</strong>
                  <span>→</span>
                </button>
              ))}
            </div>
          </Panel>
        </div>
      </>
    )
  } else if (page === "categories") {
    content = (
      <>
        <PageHeading
          kicker="CATÁLOGOS"
          title="Categorías"
          description="Administra las divisiones disponibles para tus equipos y torneos."
          action={
            <ActionButton onClick={() => openDialog("category")}>
              + Nueva categoría
            </ActionButton>
          }
        />
        <Panel
          title="Categorías activas"
          description={`${filteredCategories.length} registros`}
          action={
            <SearchField
              value={search}
              onChange={setSearch}
              placeholder="Buscar categoría..."
            />
          }
        >
          <div className="app-table-wrap">
            <table className="app-table">
              <thead>
                <tr>
                  <th>Categoría</th>
                  <th>Descripción</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                {filteredCategories.map((category) => (
                  <tr key={category.id}>
                    <td>
                      <strong>{category.name}</strong>
                    </td>
                    <td>{category.description || "—"}</td>
                    <td>
                      <span className="app-status active">Activa</span>
                    </td>
                    <td className="app-row-actions">
                      <button onClick={() => openDialog("category", category)}>
                        Editar
                      </button>
                      <button
                        className="danger-text"
                        onClick={() =>
                          void deactivate(
                            "category",
                            category.id,
                            category.name,
                          )
                        }
                      >
                        Desactivar
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {loading && <div className="app-loading">Cargando categorías…</div>}
            {!loading && filteredCategories.length === 0 && (
              <EmptyState
                title="No hay categorías"
                detail="Crea una categoría o cambia la búsqueda."
              />
            )}
          </div>
        </Panel>
      </>
    )
  } else if (page === "teams") {
    content = (
      <>
        <PageHeading
          kicker="CATÁLOGOS"
          title="Banco de equipos"
          description="Gestiona los equipos permanentes y sus datos de contacto."
          action={
            <ActionButton onClick={() => openDialog("team")}>
              + Registrar equipo
            </ActionButton>
          }
        />
        <Panel
          title="Equipos"
          description={`${filteredTeams.length} registros`}
          action={
            <SearchField
              value={search}
              onChange={setSearch}
              placeholder="Buscar equipo..."
            />
          }
        >
          <div className="app-table-wrap">
            <table className="app-table">
              <thead>
                <tr>
                  <th>Equipo</th>
                  <th>Abreviatura</th>
                  <th>Contacto</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                {filteredTeams.map((team) => (
                  <tr key={team.id}>
                    <td>
                      <div className="app-entity">
                        <span className="app-crest">
                          {shortName(team.name)}
                        </span>
                        <strong>{team.name}</strong>
                      </div>
                    </td>
                    <td>{team.short_name || "—"}</td>
                    <td>{team.contact_name || team.contact_phone || "—"}</td>
                    <td>
                      <span className="app-status active">Activo</span>
                    </td>
                    <td className="app-row-actions">
                      <button onClick={() => openDialog("team", team)}>
                        Editar
                      </button>
                      <button
                        className="danger-text"
                        onClick={() =>
                          void deactivate("team", team.id, team.name)
                        }
                      >
                        Desactivar
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {loading && <div className="app-loading">Cargando equipos…</div>}
            {!loading && filteredTeams.length === 0 && (
              <EmptyState
                title="No hay equipos"
                detail="Registra un equipo o cambia la búsqueda."
              />
            )}
          </div>
        </Panel>
      </>
    )
  } else if (page === "players") {
    content = (
      <>
        <PageHeading
          kicker="CATÁLOGOS"
          title="Banco de jugadores"
          description="Administra los perfiles de jugadores disponibles para inscripciones."
          action={
            <ActionButton onClick={() => openDialog("player")}>
              + Registrar jugador
            </ActionButton>
          }
        />
        <Panel
          title="Jugadores"
          description={`${filteredPlayers.length} registros`}
          action={
            <SearchField
              value={search}
              onChange={setSearch}
              placeholder="Buscar jugador..."
            />
          }
        >
          <div className="app-table-wrap">
            <table className="app-table">
              <thead>
                <tr>
                  <th>Jugador</th>
                  <th>Fecha de nacimiento</th>
                  <th>Contacto</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                {filteredPlayers.map((player) => (
                  <tr key={player.id}>
                    <td>
                      <strong>
                        {player.first_name} {player.last_name}
                      </strong>
                    </td>
                    <td>{displayDate(player.birth_date)}</td>
                    <td>{player.phone || player.email || "—"}</td>
                    <td>
                      <span className="app-status active">Activo</span>
                    </td>
                    <td className="app-row-actions">
                      <button onClick={() => openDialog("player", player)}>
                        Editar
                      </button>
                      <button
                        className="danger-text"
                        onClick={() =>
                          void deactivate(
                            "player",
                            player.id,
                            `${player.first_name} ${player.last_name}`,
                          )
                        }
                      >
                        Desactivar
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {loading && <div className="app-loading">Cargando jugadores…</div>}
            {!loading && filteredPlayers.length === 0 && (
              <EmptyState
                title="No hay jugadores"
                detail="Registra un jugador o cambia la búsqueda."
              />
            )}
          </div>
        </Panel>
      </>
    )
  } else if (page === "tournaments") {
    content = (
      <>
        <PageHeading
          kicker="COMPETENCIAS"
          title="Torneos"
          description="Crea competencias y administra la inscripción de equipos."
          action={
            <ActionButton onClick={() => openDialog("tournament")}>
              + Crear torneo
            </ActionButton>
          }
        />
        <Panel
          title="Torneos registrados"
          description={`${filteredTournaments.length} competencias`}
          action={
            <SearchField
              value={search}
              onChange={setSearch}
              placeholder="Buscar torneo..."
            />
          }
        >
          <div className="app-table-wrap">
            <table className="app-table">
              <thead>
                <tr>
                  <th>Torneo</th>
                  <th>Categoría</th>
                  <th>Formato</th>
                  <th>Periodo</th>
                  <th>Estado</th>
                  <th>Administrar</th>
                </tr>
              </thead>
              <tbody>
                {filteredTournaments.map((tournament) => (
                  <tr key={tournament.id}>
                    <td>
                      <strong>{tournament.name}</strong>
                    </td>
                    <td>{categoryName(tournament.category_id)}</td>
                    <td>{formatName(tournament.format)}</td>
                    <td>
                      {displayDate(tournament.start_date)} —{" "}
                      {displayDate(tournament.end_date)}
                    </td>
                    <td>
                      <span
                        className={`app-status ${tournament.status.toLowerCase()}`}
                      >
                        {statusName(tournament.status)}
                      </span>
                    </td>
                    <td>
                      <button
                        className="app-text-button"
                        onClick={() => {
                          setSelectedTournamentId(String(tournament.id))
                          go("schedule")
                        }}
                      >
                        Abrir calendario →
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {loading && <div className="app-loading">Cargando torneos…</div>}
            {!loading && filteredTournaments.length === 0 && (
              <EmptyState
                title="No hay torneos"
                detail="Crea un torneo para empezar a administrarlo."
                action={
                  <ActionButton onClick={() => openDialog("tournament")}>
                    Crear torneo
                  </ActionButton>
                }
              />
            )}
          </div>
        </Panel>
      </>
    )
  } else if (page === "rosters") {
    const filteredRoster = registeredPlayers.filter((entry) =>
      `${entry.first_name} ${entry.last_name} ${entry.team_name}`

        .toLowerCase()

        .includes(search.toLowerCase()),
    )

    content = (
      <>
        <PageHeading
          kicker="INSCRIPCIONES"
          title="Plantillas del torneo"
          description="Consulta y asigna jugadores del banco a equipos inscritos en la competencia."
          action={
            <ActionButton
              onClick={() => openDialog("register-player")}
              disabled={
                !selectedTournament ||
                registeredTeams.length === 0 ||
                players.length === 0
              }
            >
              + Inscribir jugador
            </ActionButton>
          }
        />
        <div className="app-filter-row">
          <label className="app-field">
            <span>Torneo seleccionado</span>
            <select
              value={selectedTournamentId}
              onChange={(event) => selectTournament(event.target.value)}
            >
              <option value="">Selecciona un torneo</option>
              {tournaments.map((tournament) => (
                <option key={tournament.id} value={tournament.id}>
                  {tournament.name}
                </option>
              ))}
            </select>
          </label>
          <div className="app-tournament-meta">
            <strong>
              {selectedTournament?.name ?? "Sin torneo seleccionado"}
            </strong>
            <span>
              {registeredTeams.length} equipos inscritos ·{" "}
              {registeredPlayers.length} jugadores en plantilla
            </span>
          </div>
        </div>
        <Panel
          title="Jugadores inscritos"
          description="La asignación pertenece al torneo y no cambia el equipo permanente del jugador."
          action={
            <SearchField
              value={search}
              onChange={setSearch}
              placeholder="Buscar jugador o equipo..."
            />
          }
        >
          <div className="app-table-wrap">
            <table className="app-table">
              <thead>
                <tr>
                  <th>Jugador</th>
                  <th>Equipo en el torneo</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                {filteredRoster.map((entry) => (
                  <tr key={entry.id}>
                    <td>
                      <strong>
                        {entry.first_name} {entry.last_name}
                      </strong>
                    </td>
                    <td>{entry.team_name}</td>
                    <td>
                      <span
                        className={`app-status ${
                          entry.status === "ACTIVE" ? "active" : ""
                        }`}
                      >
                        {statusName(entry.status)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!selectedTournament && (
              <EmptyState
                title="Selecciona un torneo"
                detail="Elige una competencia en la barra superior para consultar las plantillas."
              />
            )}
            {selectedTournament && filteredRoster.length === 0 && (
              <EmptyState
                title="Plantilla vacía"
                detail="Inscribe equipos y jugadores para comenzar a administrar las plantillas."
              />
            )}
          </div>
        </Panel>
      </>
    )
  } else if (page === "schedule" || page === "results") {
    content = (
      <>
        {page === "results" && activeResultMatch ? (
          <PageHeading
            kicker="CÉDULA DEL PARTIDO"
            title="Capturar resultado"
            description="Completa el marcador y los detalles del partido; al guardar o cancelar volverás a los enfrentamientos."
            action={
              <ActionButton
                variant="secondary"
                onClick={() => setActiveResultMatchId(null)}
              >
                Cancelar
              </ActionButton>
            }
          />
        ) : (
        <PageHeading
          kicker={
            page === "schedule"
              ? "ORGANIZACIÓN DE PARTIDOS"
              : "ACTUALIZACIÓN DE MARCADORES"
          }
          title={page === "schedule" ? "Calendario y jornadas" : "Resultados"}
          description={
            page === "schedule"
              ? "Consulta jornadas y genera emparejamientos para los equipos inscritos."
              : "Captura marcadores confirmados de los partidos del torneo."
          }
          action={
            page === "schedule" && selectedTournament?.format === "LEAGUE" ? (
              <ActionButton
                onClick={() => void generateSchedule()}
                disabled={
                  scheduleBusy ||
                  !selectedTournament ||
                  rounds.length > 0
                }
              >
                {scheduleBusy
                  ? "Generando..."
                  : rounds.length > 0
                    ? "Calendario generado"
                    : "⚡ Generar calendario"}
              </ActionButton>
            ) : undefined
          }
        />
        )}
        {!(page === "results" && activeResultMatch) && <div className="app-filter-row">
          <label className="app-field">
            <span>Torneo seleccionado</span>
            <select
              value={selectedTournamentId}
              onChange={(event) => selectTournament(event.target.value)}
            >
              <option value="">Selecciona un torneo</option>
              {tournaments.map((tournament) => (
                <option key={tournament.id} value={tournament.id}>
                  {tournament.name}
                </option>
              ))}
            </select>
          </label>
          {selectedTournament && (
            <label className="app-field">
              <span>Jornada</span>
              <select
                value={roundId}
                onChange={(event) => {
                  setRoundId(event.target.value)
                  setActiveResultMatchId(null)
                  setActiveScheduleMatchId(null)
                }}
              >
                <option value="">Selecciona una jornada</option>
                {rounds.map((round) => (
                  <option key={round.id} value={round.id}>
                    {[
                      round.group_name,
                      round.name || `Jornada ${round.round_number}`,
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </option>
                ))}
              </select>
            </label>
          )}
          <div className="app-tournament-meta">
            <strong>
              {selectedTournament?.name ?? "Sin torneo seleccionado"}
            </strong>
            <span>
              {registeredTeams.length} equipos inscritos · {rounds.length}{" "}
              jornadas
            </span>
          </div>
        </div>}
        {page === "schedule" &&
          selectedTournament &&
          selectedTournament.format !== "LEAGUE" && (
            <Panel
              title={`Gestión de formato · ${formatName(selectedTournament.format)}`}
              description={
                selectedTournament.format === "FLASH"
                  ? "Asigna los equipos a grupos, genera sus jornadas independientes y crea el cuadro con los clasificados."
                  : "Genera el cuadro de eliminación directa y avanza ganadores ronda por ronda."
              }
            >
              {selectedTournament.format === "CUP" ? (
                <div className="app-cup-format">
                  <div className="app-format-actions">
                    {!hasKnockoutBracket ? (
                      <>
                        <p>
                          Seeding por número de inscripción; los descansos se
                          asignan automáticamente a las mejores posiciones.
                        </p>
                        <ActionButton
                          disabled={scheduleBusy || registeredTeams.length < 2}
                          onClick={() =>
                            void submitFormatAction(
                              "cup/generate",
                              undefined,
                              (data) =>
                                `Cuadro creado: ${data.matches} partidos y ${data.byes} descansos automáticos.`,
                            )
                          }
                        >
                          {scheduleBusy ? "Generando..." : "Crear cuadro de Copa"}
                        </ActionButton>
                      </>
                    ) : (
                      <>
                        <p>
                          Ronda actual: {latestKnockoutRound?.name ?? "Eliminación"}
                          . Registra todos sus resultados antes de avanzar.
                        </p>
                        {latestKnockoutRound && (
                          <ActionButton
                            disabled={scheduleBusy || !knockoutRoundResolved}
                            onClick={() =>
                              void submitFormatAction(
                                "knockout/advance",
                                undefined,
                                (data) =>
                                  data.completed
                                    ? `Campeón: ${(data.champion as { team_name: string }).team_name}.`
                                    : `Ganadores avanzaron a ${data.round_name}.`,
                              )
                            }
                          >
                            {scheduleBusy
                              ? "Procesando..."
                              : knockoutRoundResolved
                                ? latestKnockoutMatches.length === 1
                                  ? "Declarar campeón"
                                  : "Avanzar ganadores"
                                : "Pendiente de resultados"}
                          </ActionButton>
                        )}
                      </>
                    )}
                  </div>
                </div>
              ) : (
                <div className="app-flash-format">
                  {groups.length === 0 ? (
                    <div className="app-format-actions">
                      <label className="app-field">
                        <span>Cantidad de grupos</span>
                        <input
                          type="number"
                          min="2"
                          max={Math.min(26, Math.floor(registeredTeams.length / 2))}
                          value={groupCount}
                          onChange={(event) => setGroupCount(event.target.value)}
                        />
                      </label>
                      <p>
                        Los equipos se distribuyen en serpentina para equilibrar
                        el tamaño de los grupos. Se requieren al menos dos
                        equipos por grupo.
                      </p>
                      <ActionButton
                        disabled={
                          scheduleBusy ||
                          registeredTeams.length < 4 ||
                          Number(groupCount) < 2 ||
                          Number(groupCount) > Math.floor(registeredTeams.length / 2)
                        }
                        onClick={() =>
                          void submitFormatAction(
                            "groups/generate",
                            { group_count: Number(groupCount) },
                            (data) =>
                              `Se crearon ${data.groups} grupos con ${data.teams} equipos.`,
                          )
                        }
                      >
                        {scheduleBusy ? "Asignando..." : "Crear grupos y asignar equipos"}
                      </ActionButton>
                    </div>
                  ) : (
                    <>
                      <div className="app-group-grid">
                        {groups.map((group) => (
                          <section className="app-group-card" key={group.id}>
                            <h3>{group.name}</h3>
                            {group.teams.map((team) => (
                              <div className="app-group-team" key={team.tournament_team_id}>
                                <span className="app-crest">{shortName(team.name)}</span>
                                <strong>{team.name}</strong>
                              </div>
                            ))}
                          </section>
                        ))}
                      </div>
                      {groupRounds.length === 0 ? (
                        <div className="app-format-actions">
                          <p>Los grupos están asignados. Genera una liga de todos contra todos independiente dentro de cada grupo.</p>
                          <div className="app-format-button-row">
                            <ActionButton
                              disabled={scheduleBusy}
                              variant="danger"
                              onClick={() => void cancelFlashGroups()}
                            >
                              {scheduleBusy ? "Procesando..." : "Cancelar"}
                            </ActionButton>
                            <ActionButton
                              disabled={scheduleBusy}
                              onClick={() =>
                                void submitFormatAction(
                                  "group-stage/generate",
                                  undefined,
                                  (data) =>
                                    `Calendario generado: ${data.rounds} jornadas, ${data.matches} partidos y ${data.byes} descansos.`,
                                )
                              }
                            >
                              {scheduleBusy ? "Generando..." : "Generar calendario de grupos"}
                            </ActionButton>
                          </div>
                        </div>
                      ) : (
                        <div className="app-format-actions app-format-divider">
                          <p>
                            {groupStageResolved
                              ? "La fase de grupos está completa; ya se pueden generar los clasificados."
                              : "Captura todos los resultados de grupo antes de calcular la clasificación."}
                          </p>
                          {!hasKnockoutBracket ? (
                            <div className="app-qualification-controls">
                              <label className="app-field">
                                <span>Clasificados por grupo</span>
                                <input
                                  type="number"
                                  min="1"
                                  max={Math.min(...groups.map((group) => group.teams.length))}
                                  value={qualifiersPerGroup}
                                  onChange={(event) => {
                                    const value = event.target.value
                                    setQualifiersPerGroup(value)
                                    const maximum = groups.length * Number(value)
                                    setTotalQualifiers((current) =>
                                      String(Math.max(groups.length, Math.min(Number(current) || groups.length, maximum))),
                                    )
                                  }}
                                />
                              </label>
                              <label className="app-field">
                                <span>Total al cuadro</span>
                                <input
                                  type="number"
                                  min={groups.length}
                                  max={groups.length * Number(qualifiersPerGroup)}
                                  value={totalQualifiers}
                                  onChange={(event) => setTotalQualifiers(event.target.value)}
                                />
                              </label>
                              <ActionButton
                                disabled={
                                  scheduleBusy ||
                                  !groupStageResolved ||
                                  Number(qualifiersPerGroup) < 1 ||
                                  Number(qualifiersPerGroup) > Math.min(...groups.map((group) => group.teams.length)) ||
                                  Number(totalQualifiers) < groups.length ||
                                  Number(totalQualifiers) > groups.length * Number(qualifiersPerGroup)
                                }
                                onClick={() =>
                                  void submitFormatAction(
                                    "qualification/generate",
                                    {
                                      qualifiers_per_group: Number(qualifiersPerGroup),
                                      total_qualifiers: Number(totalQualifiers),
                                    },
                                    (data) =>
                                      `Cuadro final creado con ${data.qualified_teams} equipos clasificados.`,
                                  )
                                }
                              >
                                {scheduleBusy ? "Calculando..." : "Clasificar y crear cuadro"}
                              </ActionButton>
                            </div>
                          ) : (
                            <ActionButton
                              disabled={scheduleBusy || !knockoutRoundResolved}
                              onClick={() =>
                                void submitFormatAction(
                                  "knockout/advance",
                                  undefined,
                                  (data) =>
                                    data.completed
                                      ? `Campeón: ${(data.champion as { team_name: string }).team_name}.`
                                      : `Ganadores avanzaron a ${data.round_name}.`,
                                )
                              }
                            >
                              {scheduleBusy
                                ? "Procesando..."
                                : knockoutRoundResolved
                                  ? latestKnockoutMatches.length === 1
                                    ? "Declarar campeón"
                                    : "Avanzar ganadores"
                                  : "Pendiente de resultados del cuadro"}
                            </ActionButton>
                          )}
                        </div>
                      )}
                    </>
                  )}
                </div>
              )}
            </Panel>
          )}
        {page === "schedule" &&
          selectedTournament?.format === "LEAGUE" &&
          rounds.some((round) => round.stage_type === "LEAGUE") && (
            <Panel
              title="Liguilla"
              description={
                hasKnockoutBracket
                  ? `Fase final · ${latestKnockoutRound?.name ?? "Cuadro de eliminación"}`
                  : "Inicia la fase final cuando todos los partidos regulares tengan resultado."
              }
            >
              {hasKnockoutBracket ? (
                <div className="app-league-format">
                  <div className="app-format-actions">
                    <p>
                      {selectedTournament.status === "COMPLETED"
                        ? "El torneo finalizó y el campeón ya está definido."
                        : `Ronda actual: ${latestKnockoutRound?.name ?? "Eliminación"}. Registra todos sus resultados antes de avanzar.`}
                    </p>
                    {latestKnockoutRound &&
                      selectedTournament.status !== "COMPLETED" && (
                        <ActionButton
                          disabled={scheduleBusy || !knockoutRoundResolved}
                          onClick={() =>
                            void submitFormatAction(
                              "knockout/advance",
                              undefined,
                              (data) =>
                                data.completed
                                  ? `Campeón: ${(data.champion as { team_name: string }).team_name}.`
                                  : `Ganadores avanzaron a ${data.round_name}.`,
                            )
                          }
                        >
                          {scheduleBusy
                            ? "Procesando..."
                            : knockoutRoundResolved
                              ? latestKnockoutMatches.length === 1
                                ? "Declarar campeón"
                                : "Avanzar ganadores"
                              : "Pendiente de resultados"}
                        </ActionButton>
                      )}
                  </div>
                </div>
              ) : (
                <div className="app-league-format">
                  <div className="app-format-actions">
                    <p>
                      {regularLeagueResolved
                        ? `Fase regular completa: ${regularLeagueMatches.length} partidos con resultado.`
                        : `${regularLeagueMatches.filter((match) => match.status === "COMPLETED").length} de ${regularLeagueMatches.length} partidos con resultado registrado.`}
                    </p>
                    <ActionButton
                      disabled={scheduleBusy || !regularLeagueResolved}
                      onClick={() =>
                        void submitFormatAction(
                          "league/qualification/generate",
                          undefined,
                          (data) =>
                            `Liguilla iniciada con ${data.qualified_teams} equipos clasificados.`,
                        )
                      }
                    >
                      {scheduleBusy
                        ? "Iniciando..."
                        : regularLeagueResolved
                          ? "Iniciar liguilla"
                          : "Pendiente de resultados"}
                    </ActionButton>
                  </div>
                </div>
              )}
            </Panel>
          )}
        {!selectedTournament ? (
          <Panel title="Selecciona una competencia">
            <EmptyState
              title="No hay torneo seleccionado"
              detail="Crea o selecciona un torneo para administrar sus partidos."
              action={
                <ActionButton onClick={() => openDialog("tournament")}>
                  Crear torneo
                </ActionButton>
              }
            />
          </Panel>
        ) : page === "schedule" ? (
          <Panel
            title={
              selectedRound?.name ||
              (selectedRound
                ? `Jornada ${selectedRound.round_number}`
                : "Jornadas")
            }
            description={`${roundMatches.length} partidos en esta jornada`}
            action={
              <span className="app-status">
                {statusName(selectedRound?.status ?? "PLANNED")}
              </span>
            }
          >
            {activeScheduleMatch ? (
              <MatchScheduleForm
                key={activeScheduleMatch.id}
                tournamentName={selectedTournament.name}
                categoryName={categoryName(selectedTournament.category_id)}
                stageLabel={
                  [
                    selectedRound?.stage_name,
                    selectedRound?.group_name,
                    selectedRound?.name ||
                      (selectedRound
                        ? `Jornada ${selectedRound.round_number}`
                        : ""),
                  ]
                    .filter(Boolean)
                    .join(" · ") || "Etapa pendiente"
                }
                homeName={teamName(activeScheduleMatch.home_team_id)}
                awayName={teamName(activeScheduleMatch.away_team_id)}
                match={activeScheduleMatch}
                saving={scheduleBusy}
                onCancel={() => setActiveScheduleMatchId(null)}
                onSave={(matchDate, kickoffTime) =>
                  saveMatchSchedule(activeScheduleMatch, matchDate, kickoffTime)
                }
              />
            ) : roundMatches.length > 0 ? (
              <div className="app-fixture-list">
                {roundMatches.map((match) => (
                  <div className="app-fixture" key={match.id}>
                    <div className="app-fixture-team">
                      <span className="app-crest">
                        {shortName(teamName(match.home_team_id))}
                      </span>
                      <strong>{teamName(match.home_team_id)}</strong>
                    </div>
                    <div className="app-fixture-center">
                      <span>
                        {match.is_bye
                          ? "Avanza"
                          : `${match.home_score ?? "—"} : ${match.away_score ?? "—"}`}
                      </span>
                      <small>
                        {match.is_bye
                          ? "Descanso automático"
                          : match.match_date
                            ? `${displayDate(match.match_date)}${
                                match.kickoff_time
                                  ? ` · ${match.kickoff_time.slice(0, 5)}`
                                  : ""
                              }`
                            : statusName(match.status)}
                      </small>
                    </div>
                    {!match.is_bye && (
                      <div className="app-fixture-team away">
                        <strong>{teamName(match.away_team_id)}</strong>
                        <span className="app-crest">
                          {shortName(teamName(match.away_team_id))}
                        </span>
                      </div>
                    )}
                    {!match.is_bye && (
                      <div className="app-fixture-actions">
                        <button
                          className="app-button secondary app-sheet-button"
                          type="button"
                          disabled={
                            scheduleBusy ||
                            match.status === "COMPLETED" ||
                            match.status === "IN_PROGRESS" ||
                            match.status === "CANCELLED" ||
                            match.home_score !== null ||
                            match.away_score !== null
                          }
                          onClick={() => setActiveScheduleMatchId(match.id)}
                        >
                          {match.match_date ? "Reprogramar" : "Programar encuentro"}
                        </button>
                        <button
                          className="app-button secondary app-sheet-button"
                          type="button"
                          disabled={sheetLoadingMatchId !== null}
                          onClick={() => void openMatchSheet(match.id)}
                          aria-label={`Descargar cédula del partido ${match.id}`}
                        >
                          {sheetLoadingMatchId === match.id
                            ? "Generando..."
                            : "Descargar cédula"}
                        </button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <EmptyState
                title={
                  rounds.length
                    ? "Esta jornada no tiene partidos"
                    : "Calendario aún no generado"
                }
                detail={
                  rounds.length
                    ? "Selecciona otra jornada o genera un nuevo calendario."
                    : selectedTournament.format === "LEAGUE"
                      ? "Inscribe equipos y genera los enfrentamientos automáticamente."
                      : "Configura grupos, jornadas o cuadro desde el módulo de gestión de formato."
                }
                action={
                  !rounds.length && selectedTournament.format === "LEAGUE" ? (
                    <ActionButton
                      onClick={() => void generateSchedule()}
                      disabled={scheduleBusy}
                    >
                      {scheduleBusy ? "Generando..." : "Generar calendario"}
                    </ActionButton>
                  ) : undefined
                }
              />
            )}
            <div className="app-panel-footer">
              <span>Equipos inscritos: {registeredTeams.length}</span>
              <ActionButton
                variant="secondary"
                onClick={() => setTeamRegistrationOpen(true)}
                disabled={!canRegisterTournamentTeams}
                title={
                  canRegisterTournamentTeams
                    ? "Selecciona uno o varios equipos para inscribir."
                    : "La inscripción se cierra cuando el torneo inicia o se genera su calendario/fase."
                }
              >
                + Inscribir equipo
              </ActionButton>
            </div>
          </Panel>
        ) : activeResultMatch ? (
          <Panel
            title={selectedRound?.name || `Jornada ${selectedRound?.round_number ?? ""}`}
            description="Cédula completa de resultado"
          >
            <MatchResultRow
              key={activeResultMatch.id}
              match={activeResultMatch}
              homeName={teamName(activeResultMatch.home_team_id)}
              awayName={teamName(activeResultMatch.away_team_id)}
              isKnockout={activeResultMatch.stage_type === "KNOCKOUT"}
              isEditor
              onOpenEditor={() => setActiveResultMatchId(activeResultMatch.id)}
              onCancel={() => setActiveResultMatchId(null)}
              onSave={saveResult}
            />
          </Panel>
        ) : (
          <Panel
            title={
              selectedRound?.name ||
              (selectedRound
                ? `Jornada ${selectedRound.round_number}`
                : "Partidos")
            }
            description="Los resultados guardados se usan para calcular la tabla."
          >
            {roundMatches.length ? (
              <div className="app-results-list">
                {roundMatches.map((match) => (
                  <MatchResultRow
                    key={match.id}
                    match={match}
                    homeName={teamName(match.home_team_id)}
                    awayName={teamName(match.away_team_id)}
                    isKnockout={match.stage_type === "KNOCKOUT"}
                    isEditor={false}
                    onOpenEditor={() => setActiveResultMatchId(match.id)}
                    onCancel={() => setActiveResultMatchId(null)}
                    onSave={saveResult}
                  />
                ))}
              </div>
            ) : (
              <EmptyState
                title="No hay partidos por mostrar"
                detail="Genera primero el calendario y selecciona una jornada."
              />
            )}
          </Panel>
        )}
      </>
    )
  } else if (page === "discipline") {
    content = <ExpulsionReasonsPage />
  } else {
    content = (
      <>
        <PageHeading
          kicker="CLASIFICACIÓN"
          title="Tabla de posiciones"
          description="Clasificación calculada con los resultados confirmados del torneo."
        />
        <div className="app-filter-row">
          <label className="app-field">
            <span>Torneo</span>
            <select
              value={selectedTournamentId}
              onChange={(event) => selectTournament(event.target.value)}
            >
              <option value="">Selecciona un torneo</option>
              {tournaments.map((tournament) => (
                <option key={tournament.id} value={tournament.id}>
                  {tournament.name}
                </option>
              ))}
            </select>
          </label>
          <div className="app-tournament-meta">
            <strong>
              {selectedTournament?.name ?? "Sin torneo seleccionado"}
            </strong>
            <span>Orden: puntos, diferencia de goles y goles a favor</span>
          </div>
        </div>
        {selectedTournament?.format === "FLASH" ? (
          groups.length ? (
            <div className="app-standings-groups">
              {groups.map((group) => (
                <Panel
                  key={group.id}
                  title={group.name}
                  description={`${group.standings.length} equipos · clasificación independiente`}
                >
                  {group.standings.length ? (
                    <div className="app-table-wrap">
                      <table className="app-table app-standings-table">
                        <thead>
                          <tr>
                            <th>POS</th><th>Equipo</th><th>PJ</th><th>PG</th><th>PE</th><th>PP</th><th>GF</th><th>GC</th><th>DG</th><th>PTS</th>
                          </tr>
                        </thead>
                        <tbody>
                          {group.standings.map((row) => (
                            <tr key={row.tournament_team_id}>
                              <td>{row.position}</td>
                              <td><strong>{row.team_name}</strong></td>
                              <td>{row.played}</td><td>{row.won}</td><td>{row.drawn}</td><td>{row.lost}</td>
                              <td>{row.goals_for}</td><td>{row.goals_against}</td><td>{row.goal_difference}</td>
                              <td><strong>{row.points}</strong></td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <EmptyState title="Sin equipos asignados" detail="Asigna equipos al grupo para consultar la clasificación." />
                  )}
                </Panel>
              ))}
            </div>
          ) : (
            <Panel title="Clasificación por grupos">
              <EmptyState
                title="Aún no hay grupos"
                detail="Crea los grupos desde el módulo Calendario y jornadas."
                action={<ActionButton onClick={() => go("schedule")}>Configurar fase de grupos</ActionButton>}
              />
            </Panel>
          )
        ) : selectedTournament?.format === "CUP" ? (
          <Panel title="Copa de eliminación directa">
            <EmptyState
              title="La Copa no utiliza tabla de liga"
              detail="Consulta los cruces, registra marcadores y avanza ganadores en el módulo de calendario."
              action={<ActionButton onClick={() => go("schedule")}>Administrar cuadro</ActionButton>}
            />
          </Panel>
        ) : (
          <Panel
            title="Clasificación general"
            description={`${standings.length} equipos · puntos según la configuración del torneo`}
          >
            {standings.length ? (
              <div className="app-table-wrap">
                <table className="app-table app-standings-table">
                  <thead>
                    <tr>
                      <th>POS</th><th>Equipo</th><th>PJ</th><th>PG</th><th>PE</th><th>PP</th><th>GF</th><th>GC</th><th>DG</th><th>PTS</th>
                    </tr>
                  </thead>
                  <tbody>
                    {standings.map((row, index) => (
                      <tr key={row.team.id}>
                        <td>{index + 1}</td>
                        <td>
                          <div className="app-entity">
                            <span className="app-crest">{shortName(row.team.name)}</span>
                            <strong>{row.team.name}</strong>
                          </div>
                        </td>
                        <td>{row.played}</td><td>{row.won}</td><td>{row.drawn}</td><td>{row.lost}</td>
                        <td>{row.goalsFor}</td><td>{row.goalsAgainst}</td><td>{row.goalsFor - row.goalsAgainst}</td>
                        <td><strong>{row.points}</strong></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <EmptyState
                title="Aún no hay posiciones"
                detail="Inscribe equipos y confirma resultados para calcular la clasificación."
              />
            )}
          </Panel>
        )}
      </>
    )
  }

  if (!authToken) {
    return (
      <main className="app-login-shell">
        <form className="app-login-card" onSubmit={signIn}>
          <img
            className="app-brand-logo"
            src={`${import.meta.env.BASE_URL}logo.jpg`}
            alt="Logotipo de Sport Complex La Pasión"
          />
          <p className="app-kicker">SPORT COMPLEX LA PASIÓN</p>
          <h1>Acceso de administrador</h1>
          <p>Inicia sesión para administrar torneos, resultados y sanciones.</p>
          <label className="app-field">
            <span>Correo electrónico</span>
            <input
              autoComplete="username"
              type="email"
              required
              value={loginEmail}
              onChange={(event) => setLoginEmail(event.target.value)}
            />
          </label>
          <label className="app-field">
            <span>Contraseña</span>
            <input
              autoComplete="current-password"
              type="password"
              required
              value={loginPassword}
              onChange={(event) => setLoginPassword(event.target.value)}
            />
          </label>
          {loginError && <ApiError message={loginError} />}
          <ActionButton type="submit" disabled={loginBusy}>
            {loginBusy ? "Validando..." : "Iniciar sesión"}
          </ActionButton>
        </form>
      </main>
    )
  }

  return (
    <div className="app-shell">
      {sidebarOpen && (
        <button
          className="app-overlay"
          onClick={() => setSidebarOpen(false)}
          aria-label="Cerrar menú"
        />
      )}
      <aside className={`app-sidebar ${sidebarOpen ? "open" : ""}`}>
        <div className="app-brand">
          <img
            className="app-brand-logo"
            src={`${import.meta.env.BASE_URL}logo.jpg`}
            alt="Logotipo de Sport Complex La Pasión"
          />
          <div>
            <strong>SPORT COMPLEX</strong>
            <small>LA PASIÓN</small>
          </div>
          <button
            className="app-mobile-close"
            onClick={() => setSidebarOpen(false)}
            aria-label="Cerrar"
          >
            ×
          </button>
        </div>
        <div className="app-nav-label">ADMINISTRACIÓN</div>
        <nav>
          {navItems.map((item) => (
            <button
              key={item.id}
              className={page === item.id ? "active" : ""}
              onClick={() => go(item.id)}
            >
              <span>{item.icon}</span>
              {item.label}
            </button>
          ))}
        </nav>
        <div className="app-sidebar-foot">
          <span
            className={`app-online-dot ${backendConnected ? "" : "offline"}`}
          />
          <div>
            <strong>
              {backendConnected ? "Backend conectado" : "Backend sin conexión"}
            </strong>
            <small>API Sport Complex</small>
          </div>
        </div>
      </aside>
      <main className="app-main">
        <header className="app-topbar">
          <button
            className="app-menu-button"
            onClick={() => setSidebarOpen(true)}
            aria-label="Abrir menú"
          >
            ☰
          </button>
          <div className="app-top-title">
            <span>SPORT COMPLEX LA PASIÓN</span>
            <strong>Administración deportiva</strong>
          </div>
          {page !== "matchday" && (
            <label className="app-top-tournament">
              <span>Torneo activo</span>
              <select
                value={selectedTournamentId}
                onChange={(event) => selectTournament(event.target.value)}
              >
                <option value="">Seleccionar torneo</option>
                {tournaments.map((tournament) => (
                  <option value={tournament.id} key={tournament.id}>
                    {tournament.name}
                  </option>
                ))}
              </select>
            </label>
          )}
          <button
            className="app-refresh"
            onClick={() => void refreshCatalogs()}
            disabled={loading}
          >
            {loading ? "Actualizando…" : "↻ Actualizar"}
          </button>
          <div className="app-session">
            <span>{authUser || "Administrador"}</span>
            <button className="app-text-button" onClick={signOut}>
              Cerrar sesión
            </button>
          </div>
        </header>
        <div className="app-content">
          {error && <ApiError message={error} />}
          {notice && (
            <div className="app-notice" role="status">
              <span>✓</span>
              {notice}
              <button onClick={() => setNotice("")} aria-label="Cerrar aviso">
                ×
              </button>
            </div>
          )}
          {loading &&
          !categories.length &&
          !teams.length &&
          !players.length &&
          !tournaments.length ? (
            <div className="app-loading-page">
              <span className="app-spinner" />
              Conectando con el backend…
            </div>
          ) : (
            content
          )}
          <footer className="app-footer">
            Sport Complex La Pasión <span>·</span> Administración de torneos
          </footer>
        </div>
      </main>
      {dialog && (
        <ResourceDialog
          key={`${dialog.kind}-${String(dialog.record?.id ?? "new")}`}
          kind={dialog.kind}
          record={dialog.record}
          categories={categories}
          players={players}
          registeredTeams={registeredTeams}
          saving={saving}
          onClose={() => setDialog(null)}
          onSave={saveDialog}
        />
      )}
      {teamRegistrationOpen && selectedTournament && (
        <TournamentTeamRegistrationDialog
          tournamentName={selectedTournament.name}
          teams={teams}
          registeredTeamIds={registeredTeamIds}
          saving={saving}
          onClose={() => setTeamRegistrationOpen(false)}
          onSave={registerTournamentTeams}
        />
      )}
    </div>
  )
}
