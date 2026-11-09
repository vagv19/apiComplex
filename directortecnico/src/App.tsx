import { FormEvent, useEffect, useMemo, useState } from "react";
import {
  AlertCircle,
  ArrowLeft,
  BarChart3,
  Bell,
  CalendarDays,
  Check,
  ChevronDown,
  ChevronRight,
  CircleUserRound,
  Clock3,
  Eye,
  EyeOff,
  FileWarning,
  Home,
  Info,
  KeyRound,
  LogOut,
  Mail,
  MapPin,
  Menu,
  MoreHorizontal,
  Pencil,
  Phone,
  Plus,
  Search,
  ShieldCheck,
  Shirt,
  Trophy,
  Upload,
  UserRound,
  Users,
  X,
  Zap,
} from "lucide-react";

type View = "dashboard" | "roster" | "player" | "standings" | "matches" | "account";

type SessionUser = {
  id: number;
  email: string;
  role: string;
};

type TournamentRecord = {
  id: number;
  name: string;
  category_id: number;
  format: string;
  status: string;
  start_date: string | null;
  end_date: string | null;
  points_for_win: number;
  points_for_draw: number;
  points_for_loss: number;
  rounds_count: number;
  qualified_teams: number;
  player_registration_deadline_round: number;
  allow_player_registration: number | boolean;
  player_registration_start_at: string | null;
  player_registration_end_at: string | null;
  player_registration_open: number | boolean | string;
  tie_breakers?: string | null;
};

type TournamentTeamRecord = {
  id: number;
  tournament_id: number;
  team_id: number;
  name: string;
  short_name: string | null;
  logo_url: string | null;
  status: string;
};

type AssignedTournamentTeam = {
  tournament_team_id: number;
  tournament_id: number;
  team_id: number;
  user_id?: number;
  name: string;
};

type DirectorRoster = {
  team: AssignedTournamentTeam;
  players: Partial<TournamentPlayerRecord>[];
};

type TournamentPlayerRecord = {
  id: number;
  tournament_id: number;
  tournament_team_id: number;
  team_id: number;
  player_id: number;
  status: string;
  first_name: string;
  last_name: string;
  team_name: string;
  jersey_number: number | null;
  birth_date?: string | null;
  photo_url?: string | null;
  phone?: string | null;
  email?: string | null;
  is_active?: number | boolean | string;
};

type UiPlayer = {
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  birth: string;
  birth_iso: string | null;
  number: number | null;
  status: string;
  photo: string;
  email?: string | null;
  phone?: string | null;
  photo_url?: string | null;
};

type StandingRow = {
  tournament_team_id: number;
  team_id: number;
  team_name: string;
  position: number;
  played: number;
  won: number;
  drawn: number;
  lost: number;
  goals_for: number;
  goals_against: number;
  goal_difference: number;
  points: number;
};

interface MatchRecord {
  id: number;
  match_date: string | null;
  kickoff_time: string | null;
  status: string;
  venue: string | null;
  home_team_name: string;
  away_team_name: string;
  tournament_name: string;
  home_score: number | null;
  away_score: number | null;
  round_number: number | null;
  stage_name: string | null;
  group_name: string | null;
}

const hero = "https://images.unsplash.com/photo-1431324155629-1a6deb1dec8d?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&q=85&w=1600";
const fallbackStandings = [
  ["Atlético Monclova", 10, 8, 1, 1, 27, 9, 18, 25],
  ["Deportivo FC", 10, 7, 3, 0, 24, 10, 14, 24],
  ["Real Madrid FC", 10, 6, 2, 2, 20, 11, 9, 20],
  ["Inter FC", 10, 5, 2, 3, 19, 14, 5, 17],
  ["Deportivo Norte", 10, 4, 2, 4, 17, 17, 0, 14],
  ["Halcones FC", 10, 3, 2, 5, 12, 18, -6, 11],
  ["Unión Central", 10, 2, 1, 7, 10, 24, -14, 7],
  ["Titanes", 10, 0, 1, 9, 7, 33, -26, 1],
];

const authStorageKey = "dt-session-token";
const userStorageKey = "dt-session-user";
const apiBase = (import.meta.env.VITE_API_BASE_URL || "/backend-api/api/v1").replace(/\/$/, "");
const playerImagesBase = apiBase
  .replace(/\/backend\/public\/api\/v1$/, "/backend/img/players")
  .replace(/\/api\/v1$/, "/img/players");

function getStoredToken(): string {
  return sessionStorage.getItem(authStorageKey) ?? "";
}

function setStoredToken(token: string) {
  if (token) sessionStorage.setItem(authStorageKey, token);
  else sessionStorage.removeItem(authStorageKey);
}

function getStoredUser(): SessionUser | null {
  const raw = sessionStorage.getItem(userStorageKey);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as SessionUser;
  } catch {
    return null;
  }
}

function setStoredUser(user: SessionUser | null) {
  if (user) sessionStorage.setItem(userStorageKey, JSON.stringify(user));
  else sessionStorage.removeItem(userStorageKey);
}

async function apiRequest<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getStoredToken();
  const isFormData = options.body instanceof FormData;
  const response = await fetch(`${apiBase}${path}`, {
    ...options,
    headers: {
      Accept: "application/json",
      ...(options.body && !isFormData ? { "Content-Type": "application/json" } : {}),
      ...(path !== "/auth/login" && token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  let payload: any = null;
  try {
    payload = await response.json();
  } catch {
    throw new Error(`El servidor respondió con un formato inesperado (${response.status}).`);
  }

  if (!response.ok || !payload?.success) {
    if (response.status === 401 && path !== "/auth/login") {
      setStoredToken("");
      setStoredUser(null);
      window.dispatchEvent(new Event("dt:unauthorized"));
    }
    const message = payload?.message ?? "No se pudo completar la solicitud.";
    const details = payload?.errors ? Object.values(payload.errors).flat().join(" ") : "";
    throw new Error([message, details].filter(Boolean).join(" "));
  }

  return payload.data as T;
}

function formatDate(date: string | null | undefined): string {
  if (!date) return "Sin fecha";
  const parsed = new Date(`${date}T12:00:00`);
  if (Number.isNaN(parsed.getTime())) return date;
  return new Intl.DateTimeFormat("es-MX", { day: "2-digit", month: "short", year: "numeric" }).format(parsed);
}

function formatDateTime(date: string | null | undefined): string {
  if (!date) return "Sin dato";
  const parsed = new Date(date.replace(" ", "T"));
  if (Number.isNaN(parsed.getTime())) return date;
  return new Intl.DateTimeFormat("es-MX", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  }).format(parsed);
}

function initials(name: string): string {
  return name
    .split(" ")
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("")
    .slice(0, 2);
}

function playerPhotoUrl(photoUrl: string | null | undefined): string {
  const filename = photoUrl?.split(/[\\/]/).filter(Boolean).pop();
  return filename ? `${playerImagesBase}/${encodeURIComponent(filename)}` : "";
}

function toUiPlayer(record: TournamentPlayerRecord | Partial<TournamentPlayerRecord> | null | undefined): UiPlayer {
  const first = record?.first_name ?? "Jugador";
  const last = record?.last_name ?? "";
  const name = [first, last].filter(Boolean).join(" ") || "Jugador";
  const birthDate = record?.birth_date ?? null;
  const active = record?.status
    ? record.status === "ACTIVE"
    : record?.is_active !== false && record?.is_active !== 0 && record?.is_active !== "0";
  return {
    id: record?.player_id ?? record?.id ?? 0,
    first_name: first,
    last_name: last,
    name,
    birth: birthDate ? formatDate(birthDate) : "Sin registro",
    birth_iso: birthDate,
    number: record?.jersey_number ?? null,
    status: active !== false && active !== 0 && active !== "0" ? "Activo" : "Pendiente",
    photo: playerPhotoUrl(record?.photo_url),
    photo_url: record?.photo_url ?? null,
    email: record?.email ?? null,
    phone: record?.phone ?? null,
  };
}

const Logo = ({ compact = false }: { compact?: boolean }) => (
  <div className="logo">
    <div className="logo-mark">
      <span>LP</span>
    </div>
    {!compact && (
      <div>
        <strong>SPORT COMPLEX</strong>
        <small>LA PASIÓN · CANCHA FUT7</small>
      </div>
    )}
  </div>
);

function Login({ onLogin }: { onLogin: (email: string, password: string) => Promise<void> }) {
  const [show, setShow] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const data = new FormData(e.currentTarget);
    const email = String(data.get("email") ?? "").trim();
    const password = String(data.get("password") ?? "");

    if (!email || !password) {
      setError("Completa tu correo y contraseña.");
      return;
    }

    setError(null);
    setLoading(true);
    try {
      await onLogin(email, password);
    } catch (err) {
      setError(err instanceof Error ? err.message : "No se pudo iniciar sesión.");
    } finally {
      setLoading(false);
    }
  };
  return (
    <main className="login">
      <section
        className="login-photo"
        style={{
          backgroundImage: `linear-gradient(155deg,rgba(9,30,22,.25),rgba(7,23,38,.9)),url(${hero})`,
        }}
      >
        <div className="login-brand">
          <Logo />
          <div>
            <span className="eyebrow light">GESTIÓN DEPORTIVA</span>
            <h1>
              Tu equipo.
              <br />
              Tu torneo.
              <br />
              <em>Una sola pasión.</em>
            </h1>
            <p>Gestiona tu plantilla y mantente al día con cada jornada desde cualquier lugar.</p>
          </div>
          <small>© 2026 Sport Complex La Pasión</small>
        </div>
      </section>
      <section className="login-panel">
        <div className="mobile-login-logo">
          <Logo />
        </div>
        <div className="login-form">
          <span className="eyebrow">PORTAL EXCLUSIVO PARA DT</span>
          <h2>Bienvenido de vuelta</h2>
          <p>Ingresa tus datos para acceder al portal.</p>
          {error && (
            <div className="alert danger">
              <AlertCircle size={18} />
              <span>
                <b>No pudimos iniciar sesión</b>
                <small>{error}</small>
              </span>
            </div>
          )}
          <form onSubmit={submit}>
            <label>
              Usuario o correo electrónico
              <div className={`field ${error ? "invalid" : ""}`}>
                <Mail size={19} />
                <input name="email" placeholder="nombre@ejemplo.com" defaultValue="admin@example.com" />
              </div>
            </label>
            <label>
              Contraseña
              <div className={`field ${error ? "invalid" : ""}`}>
                <KeyRound size={19} />
                <input
                  name="password"
                  type={show ? "text" : "password"}
                  placeholder="Ingresa tu contraseña"
                  defaultValue="admin123"
                />
                <button
                  type="button"
                  className="icon-button"
                  onClick={() => setShow(!show)}
                  aria-label="Mostrar contraseña"
                >
                  {show ? <EyeOff size={19} /> : <Eye size={19} />}
                </button>
              </div>
            </label>
            <button className="btn primary wide" disabled={loading}>
              {loading ? (
                <>
                  <span className="spinner" />
                  Ingresando...
                </>
              ) : (
                <>
                  Iniciar sesión <ChevronRight size={19} />
                </>
              )}
            </button>
          </form>
          <div className="secure">
            <ShieldCheck size={18} />
            <span>
              <b>Acceso seguro</b>
              <small>Tu cuenta es creada y administrada por Sport Complex.</small>
            </span>
          </div>
        </div>
      </section>
    </main>
  );
}

const nav = [
  { id: "dashboard" as View, label: "Inicio", icon: Home },
  { id: "roster" as View, label: "Mi plantilla", icon: Users },
  { id: "standings" as View, label: "Tabla de posiciones", icon: BarChart3 },
  { id: "matches" as View, label: "Calendario", icon: CalendarDays },
  { id: "account" as View, label: "Mi cuenta", icon: CircleUserRound },
];

function Shell({
  view,
  setView,
  logout,
  children,
  coachName,
  teamName,
}: {
  view: View;
  setView: (v: View) => void;
  logout: () => void;
  children: React.ReactNode;
  coachName: string;
  teamName: string;
}) {
  const [menu, setMenu] = useState(false);
  return (
    <div className="app-shell">
      <aside className={menu ? "sidebar open" : "sidebar"}>
        <div className="side-head">
          <Logo />
          <button className="close-menu" onClick={() => setMenu(false)}>
            <X />
          </button>
        </div>
        <div className="team-chip">
          <TeamCrest letters="DF" />
          <div>
            <small>MI EQUIPO</small>
            <b>{teamName}</b>
            <span>DT · {coachName}</span>
          </div>
          <ChevronDown size={16} />
        </div>
        <nav>
          <small>MENÚ PRINCIPAL</small>
          {nav.map((n) => (
            <button
              key={n.id}
              className={view === n.id || (view === "player" && n.id === "roster") ? "active" : ""}
              onClick={() => {
                setView(n.id);
                setMenu(false);
              }}
            >
              <n.icon size={19} />
              {n.label}
            </button>
          ))}
        </nav>
        <div className="side-footer">
          <div className="mini-tournament">
            <Trophy size={18} />
            <span>
              <small>TORNEO ACTIVO</small>
              <b>Liga Libre 2026</b>
            </span>
          </div>
          <button onClick={logout}>
            <LogOut size={18} /> Cerrar sesión
          </button>
        </div>
      </aside>
      {menu && (
        <button className="menu-backdrop" onClick={() => setMenu(false)} aria-label="Cerrar menú" />
      )}
      <div className="workspace">
        <header>
          <button className="menu-button" onClick={() => setMenu(true)}>
            <Menu />
          </button>
          <div>
            <small>LIGA LIBRE 2026</small>
            <b>Portal de Directores Técnicos</b>
          </div>
          <div className="header-actions">
            <button className="notification">
              <Bell size={20} />
              <i />
            </button>
            <button className="user-menu" onClick={() => setView("account")}>
              <span>JP</span>
              <div>
                <b>Juan Pérez</b>
                <small>Director Técnico</small>
              </div>
              <ChevronDown size={15} />
            </button>
          </div>
        </header>
        <div className="page">{children}</div>
        <nav className="bottom-nav">
          {nav.slice(0, 5).map((n) => (
            <button
              key={n.id}
              onClick={() => setView(n.id)}
              className={view === n.id ? "active" : ""}
            >
              <n.icon />
              <span>
                {n.label
                  .replace("Tabla de posiciones", "Tabla")
                  .replace("Mi plantilla", "Plantilla")}
              </span>
            </button>
          ))}
        </nav>
      </div>
    </div>
  );
}

const TeamCrest = ({ letters, alt = false }: { letters: string; alt?: boolean }) => (
  <div className={`crest ${alt ? "alt" : ""}`}>
    <span>{letters}</span>
  </div>
);
const Badge = ({ children, color = "green" }: { children: React.ReactNode; color?: string }) => (
  <span className={`badge ${color}`}>
    <i />
    {children}
  </span>
);

function Dashboard({
  go,
  coachName,
  teamName,
  tournamentName,
  players,
  standings,
  matches,
  tournament,
}: {
  go: (v: View) => void;
  coachName: string;
  teamName: string;
  tournamentName: string;
  players: UiPlayer[];
  standings: StandingRow[];
  matches: MatchRecord[];
  tournament: TournamentRecord | null;
}) {
  const registrationOpen = tournament ? Boolean(Number(tournament.player_registration_open)) : false;
  const playerProgress = Math.min(100, Math.round((players.length / Math.max(1, 22)) * 100));
  const topStanding = standings.find((row) => row.team_name === teamName) ?? standings[0] ?? null;
  const nextMatch = [...matches]
    .filter((match) => match.match_date && match.match_date >= new Date().toISOString().slice(0, 10) && match.status !== "COMPLETED")
    .sort((a, b) => (a.match_date ?? "").localeCompare(b.match_date ?? ""))
    .find(Boolean) ?? null;
  const recentResults = matches.filter((match) => match.status === "COMPLETED").slice(0, 3);

  return (
    <>
      <div className="welcome">
        <div>
          <span className="eyebrow">{new Date().toLocaleDateString("es-MX", { weekday: "long", day: "numeric", month: "long" }).toUpperCase()}</span>
          <h1>Bienvenido, {coachName}</h1>
          <p>
            Este es el resumen de <b>{teamName}</b> en <b>{tournamentName}</b>.
          </p>
        </div>
        <button className="btn secondary" onClick={() => go("matches")}>
          <CalendarDays size={18} /> Ver calendario
        </button>
      </div>
      <div className="stat-grid">
        <Stat
          icon={Users}
          label="Jugadores registrados"
          value={String(players.length)}
          detail={`${Math.max(0, 22 - players.length)} slots disponibles`}
          color="green"
        />
        <Stat
          icon={Trophy}
          label="Posición actual"
          value={topStanding ? `${topStanding.position}°` : "—"}
          detail={topStanding ? `de ${standings.length} equipos` : "Sin tabla disponible"}
          color="navy"
        />
        <Stat
          icon={Zap}
          label="Puntos acumulados"
          value={topStanding ? String(topStanding.points) : "0"}
          detail={topStanding ? `GF ${topStanding.goals_for} / GC ${topStanding.goals_against}` : "Sin registro"}
          color="amber"
        />
        <Stat
          icon={CalendarDays}
          label="Próximo partido"
          value={nextMatch ? nextMatch.match_date?.slice(5) ?? "—" : "—"}
          detail={nextMatch ? `${nextMatch.home_team_name} vs ${nextMatch.away_team_name}` : "Sin partidos programados"}
          color="blue"
        />
      </div>
      <div className="dashboard-grid">
        <section className="card registration">
          <div className="section-head">
            <div>
              <span className="eyebrow">GESTIÓN DE PLANTILLA</span>
              <h2>Registro de jugadores</h2>
            </div>
            <Badge>{registrationOpen ? "ABIERTO" : "CERRADO"}</Badge>
          </div>
          <p>
            {registrationOpen
              ? "El periodo de registro se encuentra habilitado dentro de las fechas configuradas."
              : "El periodo de registro está cerrado o no tiene una ventana de fechas configurada."}
          </p>
          <div className="dates">
            <div>
              <CalendarDays />
              <span>
                <small>APERTURA</small>
                <b>{formatDateTime(tournament?.player_registration_start_at)}</b>
              </span>
            </div>
            <div>
              <Clock3 />
              <span>
                <small>FECHA LÍMITE</small>
                <b>{formatDateTime(tournament?.player_registration_end_at)}</b>
              </span>
            </div>
          </div>
          <div className="progress-title">
            <span>
              <b>{players.length}</b> de 22 jugadores
            </span>
            <b>{playerProgress}%</b>
          </div>
          <div className="progress">
            <i style={{ width: `${playerProgress}%` }} />
          </div>
          <button className="text-button" onClick={() => go("roster")}>
            Administrar mi plantilla <ChevronRight />
          </button>
        </section>
        <section className="card next-match">
          <div className="section-head">
            <div>
              <span className="eyebrow">PRÓXIMO ENCUENTRO</span>
              <h2>Próximo enfrentamiento</h2>
            </div>
            <Badge color="blue">{nextMatch ? "PROGRAMADO" : "SIN FECHA"}</Badge>
          </div>
          {nextMatch ? (
            <>
              <div className="versus">
                <div>
                  <TeamCrest letters={initials(teamName)} />
                  <b>{teamName}</b>
                </div>
                <span>
                  <small>{nextMatch.match_date}</small>
                  <strong>{nextMatch.kickoff_time ?? "—"}</strong>
                  <i>VS</i>
                </span>
                <div>
                  <TeamCrest letters={initials(nextMatch.away_team_name)} alt />
                  <b>{nextMatch.away_team_name}</b>
                </div>
              </div>
              <div className="match-location">
                <MapPin size={17} />
                <span>
                  <b>{nextMatch.venue ?? "Cancha por confirmar"}</b>
                  <small>Llega 30 minutos antes del encuentro</small>
                </span>
              </div>
            </>
          ) : (
            <p>No hay partidos programados para este equipo en la fecha actual.</p>
          )}
        </section>
      </div>
      <section className="card results">
        <div className="section-head">
          <div>
            <span className="eyebrow">JORNADAS RECIENTES</span>
            <h2>Últimos resultados</h2>
          </div>
          <button className="text-button" onClick={() => go("matches")}>
            Ver historial <ChevronRight />
          </button>
        </div>
        {recentResults.length ? (
          recentResults.map((match, i) => (
            <div className="result-row" key={match.id}>
              <span className="round">J{i + 1}</span>
              <TeamCrest letters={initials(match.home_team_name)} alt={i % 2 === 0} />
              <div>
                <strong>
                  {match.home_score ?? 0} <small>—</small> {match.away_score ?? 0}
                </strong>
              </div>
              <Badge
                color={
                  match.home_score !== null && match.away_score !== null
                    ? match.home_score > match.away_score
                      ? "green"
                      : match.home_score < match.away_score
                        ? "red"
                        : "amber"
                    : "blue"
                }
              >
                {match.home_score !== null && match.away_score !== null && match.home_score > match.away_score
                  ? "GANADO"
                  : match.home_score !== null && match.away_score !== null && match.home_score < match.away_score
                    ? "PERDIDO"
                    : "EMPATE"}
              </Badge>
            </div>
          ))
        ) : (
          <div className="empty">
            <Info />
            <p>Aún no hay resultados recientes para mostrar.</p>
          </div>
        )}
      </section>
    </>
  );
}

function Stat({ icon: Icon, label, value, detail, color }: any) {
  return (
    <div className="card stat">
      <div className={`stat-icon ${color}`}>
        <Icon />
      </div>
      <div>
        <small>{label}</small>
        <strong>{value}</strong>
        <span>{detail}</span>
      </div>
    </div>
  );
}

function PageTitle({ eyebrow, title, description, action }: any) {
  return (
    <div className="page-title">
      <div>
        <button className="breadcrumb">
          Inicio <ChevronRight /> <b>{title}</b>
        </button>
        <span className="eyebrow">{eyebrow}</span>
        <h1>{title}</h1>
        <p>{description}</p>
      </div>
      {action}
    </div>
  );
}

function Roster({
  edit,
  players,
  canAddPlayers,
  teamName,
  teamError,
  teams,
  selectedTeamId,
  onTeamChange,
}: {
  edit: (player?: UiPlayer) => void;
  players: UiPlayer[];
  canAddPlayers: boolean;
  teamName: string;
  teamError: string | null;
  teams: TournamentTeamRecord[];
  selectedTeamId: number | null;
  onTeamChange: (teamId: number) => void;
}) {
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("Todos");
  const filtered = useMemo(
    () =>
      players.filter(
        (p) =>
          p.name.toLowerCase().includes(query.toLowerCase()) &&
          (status === "Todos" || p.status === status),
      ),
    [query, status],
  );
  const activePlayers = players.filter((player) => player.status === "Activo").length;
  const assignedNumbers = players.filter((player) => player.number !== null).length;
  return (
    <>
      <PageTitle
        eyebrow={`EQUIPO · ${teamName}`}
        title="Mi plantilla"
        description={`Administra los jugadores inscritos en ${teamName}.`}
        action={
          <button className="btn primary" onClick={() => edit()} disabled={!canAddPlayers}>
            <Plus /> Agregar jugador
          </button>
        }
      />
      {!canAddPlayers && (
        <div className="alert info">
          <Info />
          <span>
            <b>{teamError ? "No hay equipo asignado para este torneo" : "La inscripción de jugadores está cerrada"}</b>
            <small>{teamError ?? "El torneo no permite nuevas inscripciones de jugadores en este momento."}</small>
          </span>
        </div>
      )}
      {teams.length > 0 && (
        <label className="select">
          Equipo del torneo:
          <select
            value={selectedTeamId ?? ""}
            onChange={(event) => {
              const teamId = Number(event.target.value);
              if (Number.isInteger(teamId) && teamId > 0) onTeamChange(teamId);
            }}
          >
            {teams.map((team) => (
              <option key={team.team_id} value={team.team_id}>{team.name}</option>
            ))}
          </select>
          <ChevronDown />
        </label>
      )}
      <div className="roster-stats">
        <div>
          <Users />
          <span>
            <b>{players.length}</b>
            <small>Total de jugadores</small>
          </span>
        </div>
        <div>
          <Check />
          <span>
            <b>{activePlayers}</b>
            <small>Jugadores activos</small>
          </span>
        </div>
        <div>
          <Shirt />
          <span>
            <b>{assignedNumbers}</b>
            <small>Dorsales asignados</small>
          </span>
        </div>
      </div>
      <section className="card roster-card">
        <div className="toolbar">
          <div className="search">
            <Search />
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Buscar por nombre..."
            />
          </div>
          <label className="select">
            Estado:
            <select value={status} onChange={(e) => setStatus(e.target.value)}>
              <option>Todos</option>
              <option>Activo</option>
              <option>Pendiente</option>
            </select>
            <ChevronDown />
          </label>
        </div>
        {filtered.length ? (
          <>
            <div className="table-wrap">
              <table className="players-table">
                <thead>
                  <tr>
                    <th>JUGADOR</th>
                    <th>FECHA DE NACIMIENTO</th>
                    <th>DORSAL</th>
                    <th>ESTADO</th>
                    <th>ACCIONES</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((p) => (
                    <tr key={p.name}>
                      <td>
                        <div className="player">
                          {p.photo && <img src={p.photo} alt={p.name} />}
                          <span>
                            <b>{p.name}</b>
                            <small>ID · LP-26{p.number}8</small>
                          </span>
                        </div>
                      </td>
                      <td>{p.birth}</td>
                      <td>
                        <span className="jersey">{p.number}</span>
                      </td>
                      <td>
                        <Badge color={p.status === "Activo" ? "green" : "amber"}>{p.status}</Badge>
                      </td>
                      <td>
                        <button className="table-action" onClick={() => edit(p)}>
                          <Pencil /> Editar
                        </button>
                        <button className="dots">
                          <MoreHorizontal />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="player-cards">
              {filtered.map((p) => (
                <article key={p.name}>
                  <div className="player">
                    {p.photo && <img src={p.photo} alt={p.name} />}
                    <span>
                      <b>{p.name}</b>
                      <small>{p.birth}</small>
                    </span>
                    <span className="jersey">{p.number}</span>
                  </div>
                  <div>
                    <Badge color={p.status === "Activo" ? "green" : "amber"}>{p.status}</Badge>
                    <button className="table-action" onClick={() => edit(p)}>
                      <Pencil /> Editar
                    </button>
                  </div>
                </article>
              ))}
            </div>
            <div className="pagination">
              <span>Mostrando {filtered.length} de {players.length} jugadores</span>
            </div>
          </>
        ) : (
          <div className="empty">
            <Search />
            <h3>No encontramos jugadores</h3>
            <p>Prueba con otro nombre o modifica los filtros.</p>
            <button
              className="btn secondary"
              onClick={() => {
                setQuery("");
                setStatus("Todos");
              }}
            >
              Limpiar filtros
            </button>
          </div>
        )}
      </section>
    </>
  );
}

function PlayerForm({
  player,
  back,
  onPhotoUploaded,
  tournamentId,
  teamName,
  teamId,
  isAdmin,
  onPlayersChanged,
}: {
  player?: UiPlayer;
  back: () => void;
  onPhotoUploaded: (player: UiPlayer) => void;
  tournamentId: number | null;
  teamName: string | null;
  teamId: number | null;
  isAdmin: boolean;
  onPlayersChanged: (players: UiPlayer[]) => void;
}) {
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState(false);
  const [selectedPhoto, setSelectedPhoto] = useState<File | null>(null);
  const [photoPreview, setPhotoPreview] = useState("");
  const [photoUploading, setPhotoUploading] = useState(false);
  const [photoError, setPhotoError] = useState<string | null>(null);
  const [photoUnavailable, setPhotoUnavailable] = useState(false);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  useEffect(() => {
    if (!selectedPhoto) {
      setPhotoPreview("");
      return;
    }

    const previewUrl = URL.createObjectURL(selectedPhoto);
    setPhotoPreview(previewUrl);
    return () => URL.revokeObjectURL(previewUrl);
  }, [selectedPhoto]);

  const uploadPhoto = async () => {
    if (!player || !selectedPhoto) return;
    if (selectedPhoto.size > 5 * 1024 * 1024) {
      setPhotoError("La imagen debe pesar como máximo 5 MB.");
      return;
    }
    if (!["image/jpeg", "image/png", "image/webp"].includes(selectedPhoto.type)) {
      setPhotoError("Selecciona una imagen JPG, PNG o WEBP.");
      return;
    }

    setPhotoUploading(true);
    setPhotoError(null);
    const body = new FormData();
    body.append("photo", selectedPhoto);
    try {
      const updatedRecord = await apiRequest<Partial<TournamentPlayerRecord>>(
        `/players/${player.id}/photo`,
        { method: "POST", body },
      );
      const updatedPlayer = {
        ...player,
        ...toUiPlayer(updatedRecord),
        number: updatedRecord.jersey_number === undefined ? player.number : updatedRecord.jersey_number,
      };
      onPhotoUploaded(updatedPlayer);
      setPhotoUnavailable(false);
      setSelectedPhoto(null);
    } catch (uploadError) {
      setPhotoError(uploadError instanceof Error ? uploadError.message : "No se pudo subir la imagen.");
    } finally {
      setPhotoUploading(false);
    }
  };

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (player) return;
    const form = e.currentTarget;
    const f = new FormData(form);
    const firstName = String(f.get("first") ?? "").trim();
    const lastName = String(f.get("last") ?? "").trim();
    const birthDate = String(f.get("birth") ?? "");
    const jerseyValue = String(f.get("number") ?? "");
    const jerseyNumber = Number(jerseyValue);
    if (
      !firstName
      || !lastName
      || !birthDate
      || jerseyValue === ""
      || !Number.isInteger(jerseyNumber)
      || jerseyNumber < 0
      || jerseyNumber > 65535
      || !teamName
      || !tournamentId
      || (isAdmin && !teamId)
    ) {
      setError(true);
      return;
    }

    setError(false);
    setSaveError(null);
    setSaving(true);
    try {
      const roster = await apiRequest<DirectorRoster>(
        `/tournaments/${tournamentId}/my-team/players`,
        {
          method: "POST",
          body: JSON.stringify({
            first_name: firstName,
            last_name: lastName,
            birth_date: birthDate,
            phone: String(f.get("phone") ?? "").trim() || null,
            email: String(f.get("mail") ?? "").trim() || null,
            jersey_number: jerseyNumber,
            ...(isAdmin && teamId ? { team_id: teamId } : {}),
          }),
        },
      );
      onPlayersChanged(roster.players.map(toUiPlayer));
      setSaved(true);
      window.scrollTo({ top: 0, behavior: "smooth" });
    } catch (requestError) {
      setSaveError(requestError instanceof Error ? requestError.message : "No se pudo agregar al jugador.");
    } finally {
      setSaving(false);
    }
  };
  return (
    <>
      <button className="back" onClick={back}>
        <ArrowLeft /> Volver a mi plantilla
      </button>
      <PageTitle
        eyebrow="GESTIÓN DE PLANTILLA"
        title={player ? "Editar jugador" : "Agregar jugador"}
        description={
          player
            ? "Actualiza la información del jugador seleccionado."
            : "Registra un nuevo jugador en Deportivo FC."
        }
      />
      {!player && teamName && (
        <div className="alert info">
          <Users />
          <span>
            <b>Equipo asignado: {teamName}</b>
            <small>El jugador se registrará en este equipo para el torneo activo.</small>
          </span>
        </div>
      )}
      {saved && (
        <div className="toast">
          <span>
            <Check />
          </span>
          <div>
            <b>Jugador guardado correctamente</b>
            <small>Los cambios ya se reflejan en tu plantilla.</small>
          </div>
          <button onClick={() => setSaved(false)}>
            <X />
          </button>
        </div>
      )}
      {error && (
        <div className="alert danger">
          <AlertCircle />
          <span>
            <b>Revisa la información</b>
            <small>Completa todos los campos obligatorios marcados con *.</small>
          </span>
        </div>
      )}
      {saveError && (
        <div className="alert danger">
          <AlertCircle />
          <span>
            <b>No se pudo agregar al jugador</b>
            <small>{saveError}</small>
          </span>
        </div>
      )}
      <form className="player-form" onSubmit={submit}>
        <section className="card photo-card">
          <div className="section-head">
            <div>
              <span className="eyebrow">FOTOGRAFÍA</span>
              <h2>Foto del jugador</h2>
            </div>
          </div>
          {player?.photo && !photoUnavailable ? (
            <div className="photo-preview">
              <img
                src={player.photo}
                alt={player.name}
                onError={() => setPhotoUnavailable(true)}
              />
              <div>
                <Badge>FOTOGRAFÍA REGISTRADA</Badge>
                <b>{player.photo_url?.split(/[\\/]/).filter(Boolean).pop()}</b>
              </div>
            </div>
          ) : player ? (
            <>
              {photoPreview ? (
                <div className="photo-preview">
                  <img src={photoPreview} alt="Vista previa de la fotografía seleccionada" />
                  <b>{selectedPhoto?.name}</b>
                </div>
              ) : (
                <div className="dropzone">
                  <span>
                    <Upload />
                  </span>
                  <b>Sin fotografía registrada</b>
                  <p>Selecciona una imagen JPG, PNG o WEBP (máximo 5 MB).</p>
                </div>
              )}
              <label className="btn secondary photo-select">
                <Upload />
                {selectedPhoto ? "Elegir otra imagen" : "Seleccionar imagen"}
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  hidden
                  onChange={(event) => {
                    setSelectedPhoto(event.currentTarget.files?.[0] ?? null);
                    setPhotoError(null);
                    event.currentTarget.value = "";
                  }}
                />
              </label>
              {selectedPhoto && (
                <button
                  type="button"
                  className="btn primary wide"
                  disabled={photoUploading}
                  onClick={() => void uploadPhoto()}
                >
                  {photoUploading ? "Subiendo imagen..." : "Guardar fotografía"}
                </button>
              )}
              {photoError && (
                <div className="alert danger">
                  <AlertCircle />
                  <span>
                    <b>No se pudo guardar la fotografía</b>
                    <small>{photoError}</small>
                  </span>
                </div>
              )}
            </>
          ) : (
            <div className="dropzone">
              <span>
                <Upload />
              </span>
              <b>Primero guarda el jugador</b>
              <p>Después podrás agregar su fotografía.</p>
            </div>
          )}
          <div className="photo-tip">
            <Info />
            <span>
              <b>Recomendación</b>
              <small>Usa una fotografía clara, de frente y con buena iluminación.</small>
            </span>
          </div>
        </section>
        <section className="card details-card">
          <div className="section-head">
            <div>
              <span className="eyebrow">INFORMACIÓN PERSONAL</span>
              <h2>Datos del jugador</h2>
            </div>
            <small>* Campos obligatorios</small>
          </div>
          <div className="form-grid">
            <label>
              Nombre *
              <input
                name="first"
                defaultValue={player?.first_name ?? ""}
                placeholder="Ej. Luis Fernando"
                required={!player}
                className={error ? "invalid" : ""}
              />
              {error && <small className="field-error">Ingresa el nombre</small>}
            </label>
            <label>
              Apellido(s) *
              <input
                name="last"
                defaultValue={player?.last_name ?? ""}
                placeholder="Ej. García Martínez"
                required={!player}
                className={error ? "invalid" : ""}
              />
            </label>
            <label>
              Fecha de nacimiento *
              <input
                name="birth"
                type="date"
                defaultValue={player?.birth_iso ?? ""}
                required={!player}
                className={error ? "invalid" : ""}
              />
            </label>
            <label>
              Número de camiseta *
              <div className="input-suffix">
                <input
                  name="number"
                  type="number"
                  min="0"
                  max="65535"
                  step="1"
                  defaultValue={player?.number ?? ""}
                  placeholder="00"
                  required={!player}
                  className={error ? "invalid" : ""}
                />
                <Shirt />
              </div>
            </label>
            <label>
              Teléfono{" "}
              <div className="input-prefix">
                <Phone />
                <input
                  name="phone"
                  defaultValue={player?.phone ?? ""}
                  placeholder="866 000 0000"
                />
              </div>
              <small>Opcional</small>
            </label>
            <label>
              Correo electrónico{" "}
              <div className="input-prefix">
                <Mail />
                <input
                  name="mail"
                  type="email"
                  defaultValue={player?.email ?? ""}
                  placeholder="correo@ejemplo.com"
                />
              </div>
              <small>Opcional</small>
            </label>
          </div>
          <div className="form-actions">
            <button type="button" className="btn secondary" onClick={back}>
              Cancelar
            </button>
            <button className="btn primary" disabled={saving || saved}>
              {saving ? <span className="spinner" /> : <Check />}
              {saving ? "Guardando jugador..." : player ? "Guardar cambios" : "Agregar jugador"}
            </button>
          </div>
        </section>
      </form>
    </>
  );
}

function Standings({ standings, teamName }: { standings: StandingRow[]; teamName: string }) {
  const rows = standings.length ? standings : [];
  return (
    <>
      <PageTitle
        eyebrow="LIGA LIBRE 2026"
        title="Tabla de posiciones"
        description="Clasificación general · Categoría Libre"
      />
      <section className="card standings-card">
        <div className="standing-meta">
          <div>
            <Trophy />
            <span>
              <small>CLASIFICACIÓN</small>
              <b>{teamName}</b>
            </span>
          </div>
          <span>Actualizado recientemente</span>
        </div>
        {rows.length ? (
          <div className="table-wrap">
            <table className="standings-table">
              <thead>
                <tr>
                  {["POS", "EQUIPO", "JJ", "JG", "JE", "JP", "GF", "GC", "DG", "PTS"].map((x) => (
                    <th key={x}>{x}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((t, i) => (
                  <tr key={String(t.team_name)} className={t.team_name === teamName ? "mine" : ""}>
                    <td>
                      <span className={`position p${i + 1}`}>{i + 1}</span>
                    </td>
                    <td>
                      <div className="standing-team">
                        <TeamCrest letters={String(t.team_name).split(" ").map((x) => x[0]).join("").slice(0, 2)} />
                        <b>{t.team_name}</b>
                        {t.team_name === teamName && <small>TU EQUIPO</small>}
                      </div>
                    </td>
                    <td>{t.played}</td>
                    <td>{t.won}</td>
                    <td>{t.drawn}</td>
                    <td>{t.lost}</td>
                    <td>{t.goals_for}</td>
                    <td>{t.goals_against}</td>
                    <td>{t.goal_difference}</td>
                    <td className="points">{t.points}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="empty">
            <Info />
            <p>No hay tabla de posiciones disponible todavía.</p>
          </div>
        )}
      </section>
    </>
  );
}

function Matches({ matches, teamName }: { matches: MatchRecord[]; teamName: string }) {
  const upcomingMatches = matches.filter((match) => match.status !== "COMPLETED");
  const completedMatches = matches.filter((match) => match.status === "COMPLETED");
  return (
    <>
      <PageTitle
        eyebrow="LIGA LIBRE 2026"
        title="Calendario"
        description="Consulta tus próximos partidos y resultados anteriores."
      />
      <div className="match-list">
        {(upcomingMatches.length ? upcomingMatches : completedMatches).slice(0, 8).map((match) => (
          <article className="card match-card" key={match.id}>
            <div className="date-block">
              <small>{match.match_date ? new Date(`${match.match_date}T12:00:00`).toLocaleDateString("es-MX", { month: "short" }).replace(".", "") : "Día"}</small>
              <b>{match.match_date ? new Date(`${match.match_date}T12:00:00`).getDate() : "--"}</b>
              <span>{match.match_date ? new Date(`${match.match_date}T12:00:00`).getFullYear() : "2026"}</span>
            </div>
            <div className="match-info">
              <span>
                <Badge color={match.status === "COMPLETED" ? "green" : "blue"}>{match.status === "COMPLETED" ? "FINALIZADO" : "PROGRAMADO"}</Badge>
                <small>{match.round_number ? `Jornada ${match.round_number}` : match.stage_name ?? "Partido"}</small>
              </span>
              <div className="match-teams">
                <div>
                  <TeamCrest letters={initials(teamName)} />
                  <b>{teamName}</b>
                </div>
                <strong>{match.home_score ?? match.kickoff_time ?? "—"}</strong>
                <div>
                  <TeamCrest letters={initials(match.away_team_name)} alt />
                  <b>{match.away_team_name}</b>
                </div>
              </div>
              <div className="match-detail">
                <span>
                  <Clock3 /> {match.kickoff_time ?? "Sin horario confirmado"}
                </span>
                <span>
                  <MapPin /> {match.venue ?? "Cancha por confirmar"}
                </span>
              </div>
            </div>
          </article>
        ))}
      </div>
      {!(upcomingMatches.length || completedMatches.length) && (
        <div className="alert info">
          <Info />
          <span>
            <b>Sin partidos en la agenda</b>
            <small>Aún no hay encuentros programados para este torneo.</small>
          </span>
        </div>
      )}
    </>
  );
}

function Account({ logout, coachName, teamName }: { logout: () => void; coachName: string; teamName: string }) {
  const [password, setPassword] = useState(false);
  return (
    <>
      <PageTitle
        eyebrow="CONFIGURACIÓN"
        title="Mi cuenta"
        description="Consulta tus datos y administra la seguridad de tu cuenta."
      />
      <div className="account-grid">
        <section className="card profile-card">
          <div className="profile-hero">
            <div className="avatar">JP</div>
            <div>
              <h2>Juan Pérez</h2>
              <p>Director Técnico</p>
              <Badge>CUENTA ACTIVA</Badge>
            </div>
          </div>
          <div className="profile-data">
            <div>
              <UserRound />
              <span>
                <small>NOMBRE COMPLETO</small>
                <b>Juan Pérez Ramírez</b>
              </span>
            </div>
            <div>
              <Mail />
              <span>
                <small>CORREO ELECTRÓNICO</small>
                <b>juan.perez@deportivofc.mx</b>
              </span>
            </div>
          </div>
        </section>
        <section className="card assignment">
          <div className="section-head">
            <div>
              <span className="eyebrow">ASIGNACIÓN ACTUAL</span>
              <h2>Equipo y torneo</h2>
            </div>
            <ShieldCheck />
          </div>
          <div>
            <TeamCrest letters="DF" />
            <span>
              <small>EQUIPO ASIGNADO</small>
              <b>Deportivo FC</b>
            </span>
          </div>
          <div>
            <Trophy />
            <span>
              <small>TORNEO ASIGNADO</small>
              <b>Liga Libre 2026 · Categoría Libre</b>
            </span>
          </div>
          <p>
            <Info /> Esta información sólo puede ser modificada por el administrador.
          </p>
        </section>
      </div>
      <section className="card security">
        <div>
          <span className="security-icon">
            <KeyRound />
          </span>
          <span>
            <h3>Contraseña y seguridad</h3>
            <p>Actualiza tu contraseña periódicamente para mantener segura tu cuenta.</p>
          </span>
        </div>
        <button className="btn secondary" onClick={() => setPassword(true)}>
          Cambiar contraseña
        </button>
      </section>
      <button className="logout-card" onClick={logout}>
        <LogOut />
        <span>
          <b>Cerrar sesión</b>
          <small>Salir de forma segura del Portal de DT</small>
        </span>
        <ChevronRight />
      </button>
      {password && (
        <div className="modal-backdrop">
          <div className="modal card">
            <button className="modal-close" onClick={() => setPassword(false)}>
              <X />
            </button>
            <span className="modal-icon">
              <KeyRound />
            </span>
            <h2>Cambiar contraseña</h2>
            <p>Usa al menos 8 caracteres, una mayúscula y un número.</p>
            <label>
              Contraseña actual
              <input type="password" placeholder="••••••••" />
            </label>
            <label>
              Nueva contraseña
              <input type="password" placeholder="••••••••" />
            </label>
            <label>
              Confirmar contraseña
              <input type="password" placeholder="••••••••" />
            </label>
            <div>
              <button className="btn secondary" onClick={() => setPassword(false)}>
                Cancelar
              </button>
              <button className="btn primary" onClick={() => setPassword(false)}>
                Actualizar contraseña
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

export default function App() {
  const [logged, setLogged] = useState<boolean>((): boolean => !!getStoredToken() && !!getStoredUser());
  const [sessionUser, setSessionUser] = useState<SessionUser | null>(() => getStoredUser());
  const [view, setView] = useState<View>("dashboard");
  const [editingPlayer, setEditingPlayer] = useState<UiPlayer | undefined>();
  const [editLoading, setEditLoading] = useState(false);
  const [editError, setEditError] = useState<string | null>(null);
  const [players, setPlayers] = useState<UiPlayer[]>([]);
  const [standings, setStandings] = useState<StandingRow[]>([]);
  const [matches, setMatches] = useState<MatchRecord[]>([]);
  const [tournament, setTournament] = useState<TournamentRecord | null>(null);
  const [teamName, setTeamName] = useState("Deportivo FC");
  const [myTeam, setMyTeam] = useState<AssignedTournamentTeam | null>(null);
  const [teamError, setTeamError] = useState<string | null>(null);
  const [tournamentTeams, setTournamentTeams] = useState<TournamentTeamRecord[]>([]);
  const [selectedTeamId, setSelectedTeamId] = useState<number | null>(null);
  const isAdmin = sessionUser?.role === "admin";

  const loadPortalData = async () => {
    try {
      const tournamentPayload = await apiRequest<{ items?: TournamentRecord[] }>('/tournaments?limit=20');
      const tournamentList = tournamentPayload.items ?? [];
      const tournamentPriority = ['REGISTRATION', 'DRAFT', 'IN_PROGRESS'];
      const candidateTournaments = tournamentList
        .filter((item) => tournamentPriority.includes(item.status))
        .sort((left, right) => tournamentPriority.indexOf(left.status) - tournamentPriority.indexOf(right.status));
      const tournamentsToCheck = candidateTournaments.length ? candidateTournaments : tournamentList.slice(0, 1);
      if (isAdmin) {
        const activeTournament = tournamentsToCheck[0] ?? null;
        setTournament(activeTournament);
        if (!activeTournament) {
          setMyTeam(null);
          setTournamentTeams([]);
          setPlayers([]);
          setTeamError("No hay torneos disponibles.");
          return;
        }

        const registeredTeams = await apiRequest<TournamentTeamRecord[]>(`/tournaments/${activeTournament.id}/teams`);
        setTournamentTeams(registeredTeams);
        const savedTeamId = selectedTeamId && registeredTeams.some((team) => team.team_id === selectedTeamId)
          ? selectedTeamId
          : registeredTeams[0]?.team_id ?? null;
        setSelectedTeamId(savedTeamId);
        if (savedTeamId) {
          const roster = await apiRequest<DirectorRoster>(
            `/tournaments/${activeTournament.id}/my-team?team_id=${savedTeamId}`,
          );
          setMyTeam(roster.team);
          setTeamName(roster.team.name);
          setPlayers(roster.players.map(toUiPlayer));
          setTeamError(null);
        } else {
          setMyTeam(null);
          setPlayers([]);
          setTeamError("El torneo todavía no tiene equipos activos inscritos.");
        }
        return;
      }

      const rosterResults = await Promise.allSettled(
        tournamentsToCheck.map((item) => apiRequest<DirectorRoster>(`/tournaments/${item.id}/my-team`)),
      );
      const assignedIndex = rosterResults.findIndex((result) => result.status === 'fulfilled');
      const activeTournament = assignedIndex >= 0
        ? tournamentsToCheck[assignedIndex]
        : tournamentsToCheck[0] ?? null;
      const rosterResult = assignedIndex >= 0 ? rosterResults[assignedIndex] : rosterResults[0];

      setTournament(activeTournament);
      if (rosterResult?.status === 'fulfilled') {
        setTournamentTeams([]);
        setSelectedTeamId(rosterResult.value.team.team_id);
        setMyTeam(rosterResult.value.team);
        setTeamName(rosterResult.value.team.name);
        setPlayers(rosterResult.value.players.map(toUiPlayer));
        setTeamError(null);
      } else {
        setTournamentTeams([]);
        setSelectedTeamId(null);
        setMyTeam(null);
        setPlayers([]);
        setTeamError(rosterResult?.reason instanceof Error
          ? rosterResult.reason.message
          : "No se encontró un equipo asignado para el director técnico.");
      }

      const [standingsResult, matchesResult] = await Promise.allSettled([
        activeTournament ? apiRequest<StandingRow[]>(`/tournaments/${activeTournament.id}/standings`) : Promise.resolve([]),
        activeTournament ? apiRequest<MatchRecord[]>(`/matches/by-date?date=${new Date().toISOString().slice(0, 10)}`) : Promise.resolve([]),
      ]);

      if (standingsResult.status === 'fulfilled') setStandings(standingsResult.value ?? []);
      if (matchesResult.status === 'fulfilled') setMatches(matchesResult.value ?? []);
    } catch (error) {
      console.error('No se pudieron cargar los datos del portal.', error);
    }
  };

  useEffect(() => {
    if (!logged) return;
    void loadPortalData();
  }, [logged]);

  const handleLogin = async (email: string, password: string) => {
    const payload = await apiRequest<{ token: string; user: SessionUser }>('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });

    setStoredToken(payload.token);
    setStoredUser(payload.user);
    setSessionUser(payload.user);
    setLogged(true);
    setView('dashboard');
  };

  const logoutAction = () => {
    setStoredToken('');
    setStoredUser(null);
    setSessionUser(null);
    setLogged(false);
    setView('dashboard');
  };

  const coachName = sessionUser ? sessionUser.email.split('@')[0] : 'Admin';

  const selectRosterTeam = async (teamId: number) => {
    if (!isAdmin || !tournament) return;
    setSelectedTeamId(teamId);
    setTeamError(null);
    try {
      const roster = await apiRequest<DirectorRoster>(
        `/tournaments/${tournament.id}/my-team?team_id=${teamId}`,
      );
      setMyTeam(roster.team);
      setTeamName(roster.team.name);
      setPlayers(roster.players.map(toUiPlayer));
    } catch (error) {
      setMyTeam(null);
      setPlayers([]);
      setTeamError(error instanceof Error ? error.message : "No se pudo cargar el equipo seleccionado.");
    }
  };

  const go = (v: View) => {
    setView(v);
    window.scrollTo(0, 0);
  };

  if (!logged) return <Login onLogin={handleLogin} />;

  return (
    <Shell view={view} setView={go} logout={logoutAction} coachName={coachName} teamName={teamName}>
      {view === 'dashboard' && (
        <Dashboard
          go={go}
          coachName={coachName}
          teamName={teamName}
          tournamentName={tournament?.name ?? 'Liga Libre 2026'}
          players={players}
          standings={standings}
          matches={matches}
          tournament={tournament}
        />
      )}
      {view === 'roster' && (
        <Roster
          players={players}
          canAddPlayers={Boolean(
            tournament
            && myTeam
            && Number(tournament.player_registration_open),
          )}
          teamName={teamName}
          teamError={teamError}
          teams={isAdmin ? tournamentTeams : []}
          selectedTeamId={selectedTeamId}
          onTeamChange={(teamId) => void selectRosterTeam(teamId)}
          edit={async (player) => {
            setEditingPlayer(undefined);
            setEditError(null);
            if (player) {
              setEditLoading(true);
              go('player');
              try {
                const record = await apiRequest<Partial<TournamentPlayerRecord>>(`/players/${player.id}`);
                setEditingPlayer(toUiPlayer(record));
              } catch (error) {
                setEditError(error instanceof Error ? error.message : "No se pudo cargar el jugador.");
              } finally {
                setEditLoading(false);
              }
              return;
            }
            setEditLoading(false);
            go('player');
          }}
        />
      )}
      {view === 'player' && editLoading && (
        <div className="card empty">
          <span className="spinner" />
          <p>Cargando información del jugador...</p>
        </div>
      )}
      {view === 'player' && !editLoading && editError && (
        <div className="alert danger">
          <AlertCircle />
          <span>
            <b>No se pudo cargar el jugador</b>
            <small>{editError}</small>
          </span>
          <button className="btn secondary" onClick={() => go('roster')}>Volver a mi plantilla</button>
        </div>
      )}
      {view === 'player' && !editLoading && !editError && (
        <PlayerForm
          player={editingPlayer}
          back={() => go('roster')}
          tournamentId={tournament?.id ?? null}
          teamName={myTeam?.name ?? null}
          teamId={myTeam?.team_id ?? null}
          isAdmin={isAdmin}
          onPlayersChanged={setPlayers}
          onPhotoUploaded={(updatedPlayer) => {
            setEditingPlayer(updatedPlayer);
            setPlayers((currentPlayers) =>
              currentPlayers.map((currentPlayer) =>
                currentPlayer.id === updatedPlayer.id ? updatedPlayer : currentPlayer,
              ),
            );
          }}
        />
      )}
      {view === 'standings' && <Standings standings={standings} teamName={teamName} />}
      {view === 'matches' && <Matches matches={matches} teamName={teamName} />}
      {view === 'account' && <Account logout={logoutAction} coachName={coachName} teamName={teamName} />}
    </Shell>
  );
}
