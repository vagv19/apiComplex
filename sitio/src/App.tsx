import { useEffect, useState } from "react"

type Page = "home" | "facilities" | "league" | "flash" | "cup"
type Theme = "red" | "gray" | "black" | "white"

import { siteAssets } from "./config/site-assets"

type FacilityImage = {
  filename: string
  url: string
}

type TournamentSummary = {
  id: number
  name: string
  format: "LEAGUE" | "FLASH" | "CUP" | string
  status: string
  category_id?: number
  category_name?: string
  start_date: string | null
  end_date: string | null
}

type PublicMatch = {
  id: number
  match_date: string
  kickoff_time: string | null
  venue: string | null
  status: string
  home_score: number | string | null
  away_score: number | string | null
  home_team_name: string
  away_team_name: string
  tournament_name: string
}

type TournamentStandingRow = {
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

const API_BASE = import.meta.env.DEV ? "/backend-api" : "/backend/public"
const SITE_NAME = "Sport Complex La Pasion Cancha Fut7"
const photos = {
  hero: siteAssets.facilities.cancha,
  aerial:
    "https://images.unsplash.com/photo-1729843352938-0e10fbf96585?auto=format&fit=crop&w=1400&q=85",
  game: "https://images.unsplash.com/flagged/photo-1550413231-202a9d53a331?auto=format&fit=crop&w=1400&q=85",
  players:
    "https://images.unsplash.com/photo-1516283250450-174387a1af6b?auto=format&fit=crop&w=1200&q=85",
  goal: "https://images.unsplash.com/photo-1599204606468-5c63999e6e26?auto=format&fit=crop&w=1200&q=85",
  ball: "https://images.unsplash.com/photo-1543351611-58f69d7c1781?auto=format&fit=crop&w=1200&q=85",
  field:
    "https://images.unsplash.com/photo-1635272340267-84d197fe6ded?auto=format&fit=crop&w=1200&q=85",
}

const navItems = [
  ["Inicio", "top"],
  ["Torneos", "torneos"],
  ["Instalaciones", "instalaciones"],
  ["Ubicación", "ubicacion"],
]

async function fetchJson<T>(path: string): Promise<T> {
  const normalizedPath = path.startsWith("/api/v1") ? path : `/api/v1${path.startsWith("/") ? path : `/${path}`}`
  const response = await fetch(`${API_BASE}${normalizedPath}`)
  if (!response.ok) {
    throw new Error(`No se pudo consultar la API (${response.status}).`)
  }
  const payload = (await response.json()) as { success?: boolean; data?: T; message?: string }
  if (payload.success === false) {
    throw new Error(payload.message ?? "La API respondió con un error.")
  }
  return (payload.data === undefined ? ({} as T) : payload.data) as T
}

function formatTournamentType(value: string): string {
  switch (value) {
    case "LEAGUE":
      return "Liga"
    case "FLASH":
      return "Relámpago"
    case "CUP":
      return "Copa"
    default:
      return value || "Torneo"
  }
}

function formatTournamentStatus(value: string): string {
  switch (value) {
    case "DRAFT":
      return "Borrador"
    case "REGISTRATION":
      return "Inscripciones"
    case "IN_PROGRESS":
      return "En curso"
    case "COMPLETED":
      return "Finalizado"
    case "CANCELLED":
      return "Cancelado"
    default:
      return value || "Activo"
  }
}

function formatTournamentDateRange(startDate: string | null, endDate: string | null): string {
  if (!startDate && !endDate) return "Fechas por definir"
  if (startDate && endDate) return `${startDate} — ${endDate}`
  return startDate ?? endDate ?? "Fechas por definir"
}

function normalizePublicImageUrl(url: string): string {
  const apiPath = "/api/v1/public/tournaments/"
  const apiPathIndex = url.indexOf(apiPath)
  return apiPathIndex === -1 ? url : `${API_BASE}${url.slice(apiPathIndex)}`
}

function normalizePublicApiUrl(url: string): string {
  const apiPath = "/api/v1/"
  const apiPathIndex = url.indexOf(apiPath)
  if (apiPathIndex === -1) {
    throw new Error("La API devolvió una URL de imagen inválida.")
  }
  return `${API_BASE}${url.slice(apiPathIndex)}`
}

function getMonclovaDate(): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "America/Monterrey",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(new Date())
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]))
  return `${values.year}-${values.month}-${values.day}`
}

function Icon({ name }: { name: string }) {
  const paths: Record<string, React.ReactNode> = {
    ball: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="m12 7 3 2.2-1.1 3.5h-3.8L9 9.2 12 7ZM4.2 9.5 9 9.2M15 9.2l4.8.3M7 18l3.1-5.3M17 18l-3.1-5.3" />
      </>
    ),
    arrow: (
      <>
        <path d="M5 12h14M14 7l5 5-5 5" />
      </>
    ),
    pin: (
      <>
        <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z" />
        <circle cx="12" cy="10" r="2.5" />
      </>
    ),
    clock: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      </>
    ),
    phone: (
      <path d="M6.5 3h3l1.2 4-2 1.2a15 15 0 0 0 7 7l1.2-2 4 1.2v3c0 1.7-1.3 3-3 3A15 15 0 0 1 3.5 6c0-1.7 1.3-3 3-3Z" />
    ),
    users: (
      <>
        <circle cx="9" cy="8" r="3" />
        <path d="M3 20v-2a6 6 0 0 1 12 0v2M16 5.2a3 3 0 0 1 0 5.6M17 14a5 5 0 0 1 4 4v2" />
      </>
    ),
    trophy: (
      <>
        <path d="M8 4h8v5a4 4 0 0 1-8 0V4ZM12 13v4M8 21h8M10 17h4M8 6H4v2a4 4 0 0 0 4 4M16 6h4v2a4 4 0 0 1-4 4" />
      </>
    ),
    calendar: (
      <>
        <rect x="3" y="5" width="18" height="16" rx="2" />
        <path d="M8 3v4M16 3v4M3 10h18" />
      </>
    ),
    menu: <path d="M4 7h16M4 12h16M4 17h16" />,
    close: <path d="m6 6 12 12M18 6 6 18" />,
    chevron: <path d="m9 18 6-6-6-6" />,
    camera: (
      <>
        <path d="M4 7h4l1.5-2h5L16 7h4v13H4V7Z" />
        <circle cx="12" cy="13" r="3.5" />
      </>
    ),
  }
  return (
    <svg className="icon" viewBox="0 0 24 24" aria-hidden="true">
      {paths[name]}
    </svg>
  )
}

function Brand({ compact = false }: { compact?: boolean }) {
  return (
    <div className="brand">
      <span className="brand-mark">
        <img src={siteAssets.branding.logo} alt={SITE_NAME} />
      </span>
      {!compact && (
        <span>
          <b>{SITE_NAME}</b>
          <small>FÚTBOL AMATEUR</small>
        </span>
      )}
    </div>
  )
}

function Header({
  page,
  onPage,
  theme,
  setTheme,
}: {
  page: Page
  onPage: (p: Page, tournament?: TournamentSummary) => void
  theme: Theme
  setTheme: (t: Theme) => void
}) {
  const [open, setOpen] = useState(false)
  const go = (id: string) => {
    setOpen(false)
    if (id === "instalaciones") {
      onPage("facilities")
      return
    }
    if (id === "top") onPage("home")
    else {
      if (page !== "home") onPage("home")
      setTimeout(
        () =>
          document.getElementById(id)?.scrollIntoView({ behavior: "smooth" }),
        80,
      )
    }
  }
  return (
    <header className="site-header">
      <div className="header-inner">
        <button
          className="brand-button"
          onClick={() => onPage("home")}
          aria-label="Ir al inicio"
        >
          <Brand />
        </button>
        <nav
          className={open ? "main-nav open" : "main-nav"}
          aria-label="Navegación principal"
        >
          {navItems.map(([label, id]) => (
            <button
              key={id}
              onClick={() => go(id)}
              className={
                (page === "home" && id === "top") ||
                (page === "facilities" && id === "instalaciones")
                  ? "active"
                  : ""
              }
            >
              {label}
            </button>
          ))}
          <button className="nav-cta" onClick={() => go("torneos")}>
            Torneos activos <Icon name="arrow" />
          </button>
        </nav>
        <div className="header-tools">
          <div className="theme-switcher" aria-label="Elegir tema">
            {(["red", "gray", "black", "white"] as Theme[]).map((item) => (
              <button
                key={item}
                className={`swatch swatch-${item} ${
                  theme === item ? "selected" : ""
                }`}
                onClick={() => setTheme(item)}
                aria-label={`Tema ${item}`}
              />
            ))}
          </div>
          <button
            className="menu-button"
            onClick={() => setOpen(!open)}
            aria-label={open ? "Cerrar menú" : "Abrir menú"}
          >
            <Icon name={open ? "close" : "menu"} />
          </button>
        </div>
      </div>
    </header>
  )
}

function SectionTitle({
  kicker,
  title,
  text,
  light = false,
}: {
  kicker: string
  title: string
  text?: string
  light?: boolean
}) {
  return (
    <div className={`section-heading ${light ? "light" : ""}`}>
      <span className="kicker">{kicker}</span>
      <h2>{title}</h2>
      {text && <p>{text}</p>}
    </div>
  )
}

function Status({
  children,
  kind = "live",
}: {
  children: React.ReactNode
  kind?: "live" | "pending" | "done"
}) {
  return (
    <span className={`status ${kind}`}>
      <i />
      {children}
    </span>
  )
}

function TournamentImage({
  tournament,
  className,
}: {
  tournament: TournamentSummary
  className: string
}) {
  const [imageUrl, setImageUrl] = useState<string | null>(null)
  const [initials, setInitials] = useState("")

  useEffect(() => {
    const tournamentInitials = tournament.name
      .trim()
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((word) => word[0])
      .join("")
      .toUpperCase()
    setInitials(tournamentInitials || tournament.name.slice(0, 2).toUpperCase())
    setImageUrl(`${API_BASE}/api/v1/public/tournaments/${tournament.id}/images/${tournament.id}.jpg`)
  }, [tournament.id, tournament.name])

  return (
    <div
      className={className}
      role={imageUrl ? undefined : "img"}
      aria-label={imageUrl ? undefined : tournament.name}
    >
      {imageUrl ? (
        <img
          src={imageUrl}
          alt={`Torneo ${tournament.name}`}
          onError={() => setImageUrl(null)}
        />
      ) : (
        <span>{initials}</span>
      )}
    </div>
  )
}

function TournamentCard({
  page,
  tournament,
  onPage,
}: {
  page: Page
  tournament: TournamentSummary
  onPage: (p: Page, tournament?: TournamentSummary) => void
}) {
  return (
    <article className="tournament-card">
      <div className="card-image">
        <TournamentImage tournament={tournament} className="tournament-cover" />
        <span className="type-tag">{formatTournamentType(tournament.format)}</span>
      </div>
      <div className="card-body">
        <div className="card-meta">
          <Status>{formatTournamentStatus(tournament.status).toUpperCase()}</Status>
          <span>
            <Icon name="calendar" /> {formatTournamentDateRange(tournament.start_date, tournament.end_date)}
          </span>
        </div>
        <h3>{tournament.name}</h3>
        <p>{tournament.category_name ?? "Categoría general"}</p>
        <button className="text-link" onClick={() => onPage(page, tournament)}>
          Ver torneo <Icon name="arrow" />
        </button>
      </div>
    </article>
  )
}

function Gallery({
  images = Object.values(photos).slice(1, 7),
  title = "Momentos que hacen comunidad",
  loading = false,
  error = false,
}: {
  images?: string[]
  title?: string
  loading?: boolean
  error?: boolean
}) {
  const [selected, setSelected] = useState<number | null>(null)
  const labels = [
    "Cancha principal",
    "Jornada amateur",
    "Actividad deportiva",
    "Zona de portería",
    "Equipamiento",
    "Vista aérea",
  ]
  const move = (direction: number) =>
    selected !== null &&
    setSelected((selected + direction + images.length) % images.length)
  return (
    <>
      <div className="gallery-head">
        <SectionTitle kicker="GALERÍA" title={title} />
        <span className="photo-count">
          <Icon name="camera" /> {images.length} fotografías
        </span>
      </div>
      {images.length > 0 ? (
        <div className="gallery-grid">
          {images.map((image, index) => (
            <button
              key={image}
              className={`gallery-item item-${index + 1}`}
              onClick={() => setSelected(index)}
            >
              <img
                src={image}
                alt={`${labels[index] || `Fotografía ${index + 1}`} del torneo`}
              />
              <span>
                {labels[index] || "Ver fotografía"} <Icon name="arrow" />
              </span>
            </button>
          ))}
        </div>
      ) : (
        <p className="gallery-empty" role={error ? "alert" : undefined}>
          {loading
            ? "Cargando fotografías del torneo..."
            : error
              ? "No fue posible cargar las fotografías del torneo."
              : "Este torneo aún no tiene fotografías publicadas."}
        </p>
      )}
      {selected !== null && (
        <div
          className="lightbox"
          role="dialog"
          aria-modal="true"
          aria-label="Visor de fotografías"
          onClick={() => setSelected(null)}
        >
          <button
            className="lightbox-close"
            onClick={() => setSelected(null)}
            aria-label="Cerrar"
          >
            <Icon name="close" />
          </button>
          <button
            className="lightbox-prev"
            onClick={(e) => {
              e.stopPropagation()
              move(-1)
            }}
            aria-label="Anterior"
          >
            ‹
          </button>
          <img
            src={images[selected]}
            alt={labels[selected] || "Fotografía ampliada"}
            onClick={(e) => e.stopPropagation()}
          />
          <button
            className="lightbox-next"
            onClick={(e) => {
              e.stopPropagation()
              move(1)
            }}
            aria-label="Siguiente"
          >
            ›
          </button>
          <span>
            {selected + 1} / {images.length}
          </span>
        </div>
      )}
    </>
  )
}

function FacilitiesGallery() {
  const [images, setImages] = useState<FacilityImage[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(false)
  const [selected, setSelected] = useState<number | null>(null)

  useEffect(() => {
    let isCurrent = true

    void (async () => {
      try {
        const data = await fetchJson<FacilityImage[]>("/public/facilities/images")
        const resolvedImages = data.map((image) => ({
          ...image,
          url: normalizePublicApiUrl(image.url),
        }))
        if (isCurrent) {
          setImages(resolvedImages)
        }
      } catch (loadError) {
        console.error(loadError)
        if (isCurrent) {
          setError(true)
        }
      } finally {
        if (isCurrent) {
          setLoading(false)
        }
      }
    })()

    return () => {
      isCurrent = false
    }
  }, [])

  useEffect(() => {
    if (selected === null) return

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setSelected(null)
      } else if (event.key === "ArrowLeft") {
        setSelected((index) => index === null ? null : (index + images.length - 1) % images.length)
      } else if (event.key === "ArrowRight") {
        setSelected((index) => index === null ? null : (index + 1) % images.length)
      }
    }

    window.addEventListener("keydown", handleKeyDown)
    return () => window.removeEventListener("keydown", handleKeyDown)
  }, [images.length, selected])

  const move = (direction: number) => {
    setSelected((index) =>
      index === null ? null : (index + direction + images.length) % images.length,
    )
  }

  return (
    <main className="facilities-page container">
      <SectionTitle kicker="GALERÍA" title="Nuestras instalaciones" />
      {loading ? (
        <p className="gallery-empty">Cargando fotografías...</p>
      ) : error ? (
        <p className="gallery-empty" role="alert">
          No fue posible cargar las fotografías de las instalaciones.
        </p>
      ) : images.length === 0 ? (
        <p className="gallery-empty">No hay fotografías disponibles.</p>
      ) : (
        <div className="facility-grid">
          {images.map((image, index) => (
            <button
              className="facility-thumb"
              key={image.filename}
              onClick={() => setSelected(index)}
              aria-label={`Ampliar fotografía ${index + 1}`}
            >
              <img src={image.url} alt={`Instalaciones ${index + 1}`} loading="lazy" />
            </button>
          ))}
        </div>
      )}
      {selected !== null && (
        <div
          className="lightbox"
          role="dialog"
          aria-modal="true"
          aria-label="Galería de instalaciones"
          onClick={() => setSelected(null)}
        >
          <button
            className="lightbox-close"
            onClick={() => setSelected(null)}
            aria-label="Cerrar galería"
          >
            <Icon name="close" />
          </button>
          <button
            className="lightbox-prev"
            onClick={(event) => {
              event.stopPropagation()
              move(-1)
            }}
            aria-label="Fotografía anterior"
          >
            ‹
          </button>
          <img
            src={images[selected].url}
            alt={images[selected].filename}
            onClick={(event) => event.stopPropagation()}
          />
          <button
            className="lightbox-next"
            onClick={(event) => {
              event.stopPropagation()
              move(1)
            }}
            aria-label="Fotografía siguiente"
          >
            ›
          </button>
          <span>{selected + 1} / {images.length}</span>
        </div>
      )}
    </main>
  )
}

function Home({
  onPage,
  tournaments,
  matchOfDay,
  matchOfDayLoading,
  matchOfDayError,
}: {
  onPage: (p: Page, tournament?: TournamentSummary) => void
  tournaments: TournamentSummary[]
  matchOfDay: PublicMatch | null
  matchOfDayLoading: boolean
  matchOfDayError: boolean
}) {
  const featured = tournaments.slice(0, 3)

  return (
    <main>
      <section className="hero" id="top">
        <img
          src={photos.hero}
          alt={SITE_NAME}
        />
        <div className="hero-shade" />
        <div className="hero-content container">
          <span className="eyebrow">EL FÚTBOL DE NUESTRA COMUNIDAD</span>
          <h1>
            Donde empieza
            <br />
            <em>la competencia.</em>
          </h1>
          <p>
            Torneos amateur, instalaciones y resultados en un mismo lugar.
            Información pública y siempre a la mano.
          </p>
          <div className="hero-actions">
            <button
              className="button primary"
              onClick={() =>
                document
                  .getElementById("torneos")
                  ?.scrollIntoView({ behavior: "smooth" })
              }
            >
              Consultar torneos <Icon name="arrow" />
            </button>
            <button
              className="button ghost"
              onClick={() =>
                document
                  .getElementById("ubicacion")
                  ?.scrollIntoView({ behavior: "smooth" })
              }
            >
              <Icon name="pin" /> Cómo llegar
            </button>
          </div>
        </div>
        <div className="hero-score">
          <span>
            {matchOfDayLoading
              ? "CONSULTANDO AGENDA DE HOY"
              : matchOfDay
                ? "PRIMER PARTIDO DE HOY"
                : matchOfDayError
                  ? "AGENDA NO DISPONIBLE"
                  : "SIN PARTIDOS DE HOY"}
          </span>
          <div className="hero-match-teams">
            {matchOfDay ? (
              <>
                <b>{matchOfDay.home_team_name}</b>
                <i>
                  {matchOfDay.status !== "SCHEDULED"
                    && matchOfDay.home_score !== null
                    && matchOfDay.away_score !== null
                    ? `${matchOfDay.home_score} - ${matchOfDay.away_score}`
                    : "VS"}
                </i>
                <b>{matchOfDay.away_team_name}</b>
              </>
            ) : (
              <b>{matchOfDayLoading ? "..." : "—"}</b>
            )}
          </div>
          {matchOfDay ? (
            <>
              <small>{matchOfDay.kickoff_time ? matchOfDay.kickoff_time.slice(0, 5) : "Horario por confirmar"}</small>
              <small>{matchOfDay.venue || matchOfDay.tournament_name}</small>
            </>
          ) : (
            <small>
              {matchOfDayLoading
                ? "Cargando el calendario"
                : matchOfDayError
                  ? "No fue posible consultar los partidos"
                  : "No hay partidos programados para hoy"}
            </small>
          )}
        </div>
        <div className="scroll-cue">
          DESCUBRE MÁS <span />
        </div>
      </section>

      <section className="intro section container" id="instalaciones">
        <div className="intro-copy">
          <SectionTitle
            kicker="NUESTRA UNIDAD"
            title="Un espacio para jugar, competir y convivir."
            text="Somos un punto de encuentro para futbolistas, familias y aficionados. Organizamos competencias amateur en instalaciones pensadas para disfrutar cada jornada."
          />
          <button className="text-link" onClick={() => onPage("facilities")}>
            Conocer las instalaciones <Icon name="arrow" />
          </button>
        </div>
        <div className="feature-grid">
          <article>
            <span>
              <Icon name="ball" />
            </span>
            <h3>Canchas</h3>
            <p>Espacios configurables para fútbol amateur.</p>
          </article>
          <article>
            <span>
              <Icon name="users" />
            </span>
            <h3>Comunidad</h3>
            <p>Torneos para distintas categorías.</p>
          </article>
          <article>
            <span>
              <Icon name="clock" />
            </span>
            <h3>Horarios</h3>
            <p>Lun–Dom · Horario por confirmar.</p>
          </article>
          <article>
            <span>
              <Icon name="phone" />
            </span>
            <h3>Contacto</h3>
            <p>866 100 7219 · sportcomplexlapasion@outlook.com</p>
          </article>
        </div>
      </section>

      <section className="tournaments section" id="torneos">
        <div className="container">
          <div className="split-heading">
            <SectionTitle
              kicker="TEMPORADA ACTUAL"
              title="Torneos activos"
              text="Consulta posiciones, calendarios, goleadores y fotografías de cada competencia."
              light
            />
            <div className="big-number">
              {featured.length}<span>COMPETENCIAS</span>
            </div>
          </div>
          <div className="tournament-grid">
            {featured.map((tournament) => (
              <TournamentCard
                key={tournament.id}
                page={
                  tournament.format === "FLASH"
                    ? "flash"
                    : tournament.format === "CUP"
                      ? "cup"
                      : "league"
                }
                tournament={tournament}
                onPage={onPage}
              />
            ))}
          </div>
          {featured.length === 0 && (
            <p className="demo-disclaimer">
              No hay torneos activos publicados en este momento.
            </p>
          )}
        </div>
      </section>

      <section className="location section" id="ubicacion">
        <div className="map-frame" aria-label="Mapa de Google Maps de la ubicación del complejo">
          <iframe
            title="Mapa de Sport Complex La Pasion Cancha Fut7"
            src="https://www.google.com/maps?q=26.865402746279102,-101.43032393076794&t=k&z=17&output=embed"
            loading="lazy"
            referrerPolicy="no-referrer-when-downgrade"
            allowFullScreen
            style={{ width: "100%", height: "100%", border: 0, display: "block" }}
          />
        </div>
        <div className="location-card">
          <SectionTitle kicker="ENCUÉNTRANOS" title="Aquí se juega." />
          <div className="contact-line">
            <Icon name="pin" />
            <div>
              <b>Dirección</b>
              <p>Valle Olimpica, Monclova, Coahuila, Mexico, 25796</p>
            </div>
          </div>
          <div className="contact-line">
            <Icon name="arrow" />
            <div>
              <b>Referencias</b>
              <p>Ubicación oficial del complejo deportivo Sport Complex La Pasion Cancha Fut7.</p>
            </div>
          </div>
          <div className="contact-line">
            <Icon name="clock" />
            <div>
              <b>Atención</b>
              <p>Consulta horarios activos directamente con la organización.</p>
            </div>
          </div>
          <a
            className="button primary"
            href="https://maps.google.com/?q=Valle%20Olimpica%20Monclova%20Coahuila%20Mexico%2025796"
            target="_blank"
            rel="noreferrer"
          >
            Abrir en Google Maps <Icon name="arrow" />
          </a>
          <small className="config-note">
            Dirección oficial actualizada para la ubicación del complejo.
          </small>
        </div>
      </section>
    </main>
  )
}

function Standings({
  data = teams,
  qualified = 4,
}: {
  data?: string[][]
  qualified?: number
}) {
  const heads = [
    "POS",
    "EQUIPO",
    "JJ",
    "JG",
    "JE",
    "JP",
    "GF",
    "GC",
    "DIF",
    "PTS",
  ]
  return (
    <div className="table-wrap">
      <table className="standings">
        <thead>
          <tr>
            {heads.map((h) => (
              <th key={h}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.map((row, index) => (
            <tr key={row[1]} className={index < qualified ? "qualified" : ""}>
              {row.map((cell, i) => (
                <td key={i}>
                  {i === 1 ? (
                    <div className="team-name">
                      <span className={`team-badge badge-${index % 5}`}>
                        {cell.slice(0, 2).toUpperCase()}
                      </span>
                      <b>{cell}</b>
                    </div>
                  ) : (
                    cell
                  )}
                  {i === 0 && index < qualified && (
                    <i className="qualifier-mark" />
                  )}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      <div className="table-legend">
        <span>
          <i /> Zona de clasificación
        </span>
      </div>
    </div>
  )
}

function MatchCard({
  home,
  away,
  date,
  stage,
  score,
}: {
  home: string
  away: string
  date: string
  stage: string
  score?: string
}) {
  return (
    <article className="match-card">
      <div className="match-top">
        <span>{stage}</span>
        <Status kind={score ? "done" : "pending"}>
          {score ? "FINALIZADO" : "PROGRAMADO"}
        </Status>
      </div>
      <div className="match-teams">
        <div>
          <span className="team-badge badge-0">
            {home.slice(0, 2).toUpperCase()}
          </span>
          <b>{home}</b>
        </div>
        <strong>{score || "VS"}</strong>
        <div>
          <span className="team-badge badge-2">
            {away.slice(0, 2).toUpperCase()}
          </span>
          <b>{away}</b>
        </div>
      </div>
      <div className="match-date">
        <Icon name="calendar" /> {date}
      </div>
    </article>
  )
}

function Scorers({ list = [["1", "Sin registros", "—", "0"]] }: { list?: Array<[string, string, string, string]> }) {
  return (
    <div className="scorers">
      <div className="scorer-head">
        <span>POS</span>
        <span>JUGADOR</span>
        <span>EQUIPO</span>
        <span>GOLES</span>
      </div>
      {list.map((row, i) => (
        <div className={`scorer-row top-${i + 1}`} key={`${row[1]}-${i}`}>
          <strong>{row[0]}</strong>
          <div>
            <span className="avatar">
              {row[1]
                .split(" ")
                .filter(Boolean)
                .map((n) => n[0])
                .join("") || "?"}
            </span>
            <b>{row[1]}</b>
          </div>
          <span>{row[2]}</span>
          <strong>{row[3]}</strong>
        </div>
      ))}
      <small>Información pública del backend · No incluye goles en propia puerta.</small>
    </div>
  )
}

function CupBracket() {
  const rounds = [
    {
      name: "CUARTOS DE FINAL",
      games: [
        ["Deportivo Norte", "2", "Titanes", "0"],
        ["Barrio FC", "1", "Academia 90", "0"],
        ["Atlético Central", "3", "Halcones", "2"],
        ["Unión Reforma", "1", "San Miguel", "0"],
      ],
    },
    {
      name: "SEMIFINALES",
      games: [
        ["Deportivo Norte", "—", "Barrio FC", "—"],
        ["Atlético Central", "—", "Unión Reforma", "—"],
      ],
    },
    { name: "FINAL", games: [["Por definir", "—", "Por definir", "—"]] },
  ]
  return (
    <div className="bracket-scroll">
      <div className="bracket">
        {rounds.map((round, ri) => (
          <div className={`bracket-round round-${ri + 1}`} key={round.name}>
            <h3>{round.name}</h3>
            <div className="round-games">
              {round.games.map((game, gi) => (
                <div
                  className={`bracket-game ${ri ? "pending-game" : ""}`}
                  key={gi}
                >
                  <div>
                    <span className="mini-badge">{game[0].slice(0, 2)}</span>
                    <b>{game[0]}</b>
                    <strong>{game[1]}</strong>
                  </div>
                  <div>
                    <span className="mini-badge alt">
                      {game[2].slice(0, 2)}
                    </span>
                    <b>{game[2]}</b>
                    <strong>{game[3]}</strong>
                  </div>
                  <small>{ri ? "PENDIENTE" : "FINALIZADO"}</small>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

function TournamentDetail({
  tournament,
  onPage,
}: {
  tournament: TournamentSummary
  onPage: (p: Page, tournament?: TournamentSummary) => void
}) {
  const [groups, setGroups] = useState<Array<{ id: number; name: string }>>([])
  const [activeGroupId, setActiveGroupId] = useState<number | null>(null)
  const [standings, setStandings] = useState<TournamentStandingRow[]>([])
  const [matches, setMatches] = useState<Array<{ home: string; away: string; date: string; stage: string }>>([])
  const [scorers, setScorers] = useState<Array<[string, string, string, string]>>([])
  const [galleryImages, setGalleryImages] = useState<string[]>([])
  const [galleryLoading, setGalleryLoading] = useState(true)
  const [galleryError, setGalleryError] = useState(false)
  const type = tournament.format === "FLASH" ? "flash" : tournament.format === "CUP" ? "cup" : "league"

  useEffect(() => {
    let isCurrent = true
    setGalleryImages([])
    setGalleryLoading(true)
    setGalleryError(false)

    void (async () => {
      try {
        const images = await fetchJson<Array<{ url: string }>>(
          `/public/tournaments/${tournament.id}/images`,
        )
        if (isCurrent) {
          setGalleryImages(images.map((image) => normalizePublicImageUrl(image.url)))
        }
      } catch (error) {
        console.error(error)
        if (isCurrent) {
          setGalleryError(true)
        }
      } finally {
        if (isCurrent) {
          setGalleryLoading(false)
        }
      }
    })()

    return () => {
      isCurrent = false
    }
  }, [tournament.id])

  useEffect(() => {
    void (async () => {
      try {
        let nextStandings: TournamentStandingRow[] = []

        if (tournament.format === "FLASH") {
          const flashGroups = await fetchJson<Array<{ id: number; name: string }>>(`/tournaments/${tournament.id}/groups`).catch(() => [])
          setGroups(flashGroups)
          if (flashGroups.length > 0) {
            const selectedGroupId = activeGroupId ?? flashGroups[0].id
            setActiveGroupId(selectedGroupId)
            nextStandings = await fetchJson<TournamentStandingRow[]>(`/groups/${selectedGroupId}/standings`).catch(() => [])
            setStandings(nextStandings)
          } else {
            nextStandings = await fetchJson<TournamentStandingRow[]>(`/tournaments/${tournament.id}/standings`).catch(() => [])
            setStandings(nextStandings)
          }
        } else if (tournament.format === "LEAGUE") {
          nextStandings = await fetchJson<TournamentStandingRow[]>(`/tournaments/${tournament.id}/standings`).catch(() => [])
          setStandings(nextStandings)
        } else {
          setStandings([])
        }

        const [roundsData, scorersData] = await Promise.all([
          fetchJson<Array<{ id: number; round_number: number; name: string | null; stage_name?: string }>>(`/tournaments/${tournament.id}/rounds`).catch(() => []),
          fetchJson<Array<{ position: number; player_name: string; team_name: string; total_goals: number }>>(`/public/tournaments/${tournament.id}/top-scorers?limit=5`).catch(() => []),
        ])

        const nextMatches: Array<{ home: string; away: string; date: string; stage: string }> = []
        for (const round of roundsData.slice(0, 2)) {
          const roundMatches = await fetchJson<Array<{ home_team_name: string; away_team_name: string; match_date: string | null; kickoff_time: string | null; status: string; group_name?: string | null }>>(`/rounds/${round.id}/matches`).catch(() => [])
          for (const match of roundMatches.slice(0, 2)) {
            if (!match.home_team_name || !match.away_team_name) continue
            nextMatches.push({
              home: match.home_team_name,
              away: match.away_team_name,
              date: match.match_date ? `${match.match_date}${match.kickoff_time ? ` · ${match.kickoff_time}` : ''}` : 'Fecha por confirmar',
              stage: match.group_name ? `GRUPO ${match.group_name}` : `${round.name ?? `JORNADA ${round.round_number}`}`,
            })
          }
        }
        setMatches(nextMatches.length ? nextMatches : [
          { home: 'Por confirmar', away: 'Por confirmar', date: 'Fecha por confirmar', stage: 'AGENDA' },
        ])

        setScorers(
          scorersData.map((scorer) => [String(scorer.position), scorer.player_name, scorer.team_name, String(scorer.total_goals)]),
        )
      } catch (error) {
        console.error(error)
      }
    })()
  }, [tournament.id, tournament.format, activeGroupId])

  useEffect(() => {
    if (tournament.format !== "FLASH" || !activeGroupId) {
      return
    }
    void (async () => {
      const groupStandings = await fetchJson<TournamentStandingRow[]>(`/groups/${activeGroupId}/standings`).catch(() => [])
      setStandings(groupStandings)
    })()
  }, [activeGroupId, tournament.format, tournament.id])

  const standingRows = standings.length
    ? standings.map((row) => [String(row.position), row.team_name, String(row.played), String(row.won), String(row.drawn), String(row.lost), String(row.goals_for), String(row.goals_against), String(row.goal_difference), String(row.points)])
    : []

  const currentGroup = groups.find((groupItem) => groupItem.id === activeGroupId) ?? groups[0]

  return (
    <main className="detail-page">
      <section className="tournament-hero">
        <TournamentImage tournament={tournament} className="tournament-hero-cover" />
        <div className="hero-shade" />
        <div className="container tournament-hero-content">
          <button className="back-link" onClick={() => onPage("home")}>
            ← Volver a torneos
          </button>
          <div className="tournament-tags">
            <span>{formatTournamentType(tournament.format).toUpperCase()}</span>
            <Status>{formatTournamentStatus(tournament.status).toUpperCase()}</Status>
          </div>
          <h1>{tournament.name}</h1>
          <p>
            {tournament.category_name ?? "Categoría general"} <i /> {formatTournamentDateRange(tournament.start_date, tournament.end_date)}
          </p>
        </div>
      </section>
      <nav className="detail-nav">
        <div className="container">
          <a href="#competencia">Competencia</a>
          <a href="#partidos">Próximos partidos</a>
          <a href="#goleo">Goleo</a>
          <a href="#fotos">Fotografías</a>
        </div>
      </nav>
      <section className="detail-section container" id="competencia">
        <SectionTitle
          kicker={type === "cup" ? "ELIMINACIÓN DIRECTA" : "CLASIFICACIÓN"}
          title={
            type === "cup"
              ? "Cuadro de competencia"
              : type === "flash"
                ? "Posiciones por grupo"
                : "Tabla general"
          }
          text={
            type === "cup"
              ? "Los ganadores avanzan a la siguiente ronda."
              : "Actualización desde la información pública del backend."
          }
        />
        {type === "flash" && groups.length > 0 && (
          <div className="group-selector" role="tablist">
            {groups.map((groupItem) => (
              <button
                key={groupItem.id}
                className={activeGroupId === groupItem.id ? "active" : ""}
                onClick={() => setActiveGroupId(groupItem.id)}
              >
                {`GRUPO ${groupItem.name.replace(/^Grupo\s*/i, "")}`}
              </button>
            ))}
          </div>
        )}
        {type === "cup" ? (
          <CupBracket />
        ) : (
          <Standings data={standingRows} qualified={type === "flash" ? 2 : 4} />
        )}
      </section>
      <section className="detail-section soft-bg" id="partidos">
        <div className="container">
          <div className="heading-action">
            <SectionTitle
              kicker="AGENDA"
              title={type === "cup" ? "Siguiente ronda" : "Próximos partidos"}
              text="Horarios sujetos a confirmación por la organización."
            />
            <button className="text-link">
              Ver calendario completo <Icon name="arrow" />
            </button>
          </div>
          <div className="matches-grid">
            {matches.map((match, index) => (
              <MatchCard
                key={`${match.home}-${match.away}-${index}`}
                home={match.home}
                away={match.away}
                date={match.date}
                stage={match.stage}
              />
            ))}
          </div>
        </div>
      </section>
      <section className="detail-section container" id="goleo">
        <SectionTitle
          kicker="ESTADÍSTICAS"
          title="Tabla de goleo"
          text="Máximos anotadores de la competencia."
        />
        <Scorers list={scorers.length ? scorers : [["1", "Sin registros", "—", "0"], ["2", "Sin registros", "—", "0"], ["3", "Sin registros", "—", "0"]]} />
      </section>
      <section className="detail-section photo-section" id="fotos">
        <div className="container">
          <Gallery
            images={galleryImages}
            title="Fotografías del torneo"
            loading={galleryLoading}
            error={galleryError}
          />
        </div>
      </section>
    </main>
  )
}

function Footer({ onPage }: { onPage: (p: Page) => void }) {
  return (
    <footer>
      <div className="footer-top container">
        <div>
          <Brand />
          <p>Fútbol amateur, comunidad y competencia.</p>
          <small>{SITE_NAME}</small>
        </div>
        <div>
          <b>NAVEGACIÓN</b>
          <button onClick={() => onPage("home")}>Inicio</button>
          <button onClick={() => onPage("league")}>Torneos</button>
          <button onClick={() => onPage("facilities")}>Instalaciones</button>
        </div>
        <div>
          <b>CONTACTO</b>
          <p>866 100 7219</p>
          <p>sportcomplexlapasion@outlook.com</p>
          <p>Valle Olimpica, Monclova, Coahuila, Mexico, 25796</p>
        </div>
        <div>
          <b>SÍGUENOS</b>
          <div className="socials">
            <button aria-label="Facebook">f</button>
            <button aria-label="Instagram">ig</button>
            <button aria-label="YouTube">yt</button>
          </div>
        </div>
      </div>
      <div className="footer-bottom container">
        <span>© {new Date().getFullYear()} {SITE_NAME}. Derechos reservados.</span>
        <span>Sitio público · Sin registro</span>
      </div>
    </footer>
  )
}

export default function App() {
  const [page, setPage] = useState<Page>("home")
  const [theme, setTheme] = useState<Theme>("red")
  const [tournaments, setTournaments] = useState<TournamentSummary[]>([])
  const [selectedTournament, setSelectedTournament] = useState<TournamentSummary | null>(null)
  const [matchOfDay, setMatchOfDay] = useState<PublicMatch | null>(null)
  const [matchOfDayLoading, setMatchOfDayLoading] = useState(true)
  const [matchOfDayError, setMatchOfDayError] = useState(false)

  useEffect(() => {
    void (async () => {
      try {
        const data = await fetchJson<{ items?: TournamentSummary[] }>(`/public/tournaments?page=1&limit=12`)
        setTournaments(data.items ?? [])
        if (!selectedTournament && (data.items ?? []).length > 0) {
          setSelectedTournament(data.items[0])
        }
      } catch (error) {
        console.error(error)
      }
    })()
  }, [])

  useEffect(() => {
    let isCurrent = true

    void (async () => {
      try {
        const date = getMonclovaDate()
        const match = await fetchJson<PublicMatch | null>(
          `/public/matches/first?date=${encodeURIComponent(date)}`,
        )
        if (isCurrent) {
          setMatchOfDay(match)
        }
      } catch (error) {
        console.error(error)
        if (isCurrent) {
          setMatchOfDayError(true)
        }
      } finally {
        if (isCurrent) {
          setMatchOfDayLoading(false)
        }
      }
    })()

    return () => {
      isCurrent = false
    }
  }, [])

  const changePage = (next: Page, tournament?: TournamentSummary) => {
    if (tournament) {
      setSelectedTournament(tournament)
    }
    setPage(next)
    window.scrollTo({ top: 0, behavior: "smooth" })
  }

  useEffect(() => {
    document.documentElement.dataset.theme = theme
  }, [theme])

  const detailTournament =
    page === "home" || page === "facilities"
      ? null
      : selectedTournament ??
        tournaments.find((item) =>
          (page === "league" && item.format === "LEAGUE") ||
          (page === "flash" && item.format === "FLASH") ||
          (page === "cup" && item.format === "CUP"),
        ) ??
        tournaments[0] ??
        null

  return (
    <>
      <Header
        page={page}
        onPage={changePage}
        theme={theme}
        setTheme={setTheme}
      />
      {page === "home" ? (
        <Home
          onPage={changePage}
          tournaments={tournaments}
          matchOfDay={matchOfDay}
          matchOfDayLoading={matchOfDayLoading}
          matchOfDayError={matchOfDayError}
        />
      ) : page === "facilities" ? (
        <FacilitiesGallery />
      ) : detailTournament ? (
        <TournamentDetail tournament={detailTournament} onPage={changePage} />
      ) : (
        <Home
          onPage={changePage}
          tournaments={tournaments}
          matchOfDay={matchOfDay}
          matchOfDayLoading={matchOfDayLoading}
          matchOfDayError={matchOfDayError}
        />
      )}
      <Footer onPage={changePage} />
    </>
  )
}
