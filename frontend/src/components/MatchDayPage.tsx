import { useEffect, useMemo, useState } from "react"

import { api, type MatchDayFixture } from "../lib/api"

function localDateString(date: Date): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, "0")
  const day = String(date.getDate()).padStart(2, "0")
  return `${year}-${month}-${day}`
}

function shiftDate(value: string, days: number): string {
  const date = new Date(`${value}T12:00:00`)
  date.setDate(date.getDate() + days)
  return localDateString(date)
}

function readableDate(value: string): string {
  const date = new Date(`${value.slice(0, 10)}T00:00:00`)
  return new Intl.DateTimeFormat("es-MX", {
    weekday: "long",
    day: "2-digit",
    month: "long",
    year: "numeric",
  }).format(date)
}

function statusLabel(status: string): string {
  return (
    {
      SCHEDULED: "Programado",
      IN_PROGRESS: "En curso",
      COMPLETED: "Finalizado",
      POSTPONED: "Aplazado",
      CANCELLED: "Cancelado",
    }[status] ?? status
  )
}

export default function MatchDayPage() {
  const [selectedDate, setSelectedDate] = useState(() =>
    localDateString(new Date()),
  )
  const [reloadKey, setReloadKey] = useState(0)
  const [matches, setMatches] = useState<MatchDayFixture[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState("")

  useEffect(() => {
    let active = true
    setLoading(true)
    setError("")

    api
      .get<MatchDayFixture[]>(
        `/matches/by-date?date=${encodeURIComponent(selectedDate)}`,
      )
      .then((rows) => {
        if (active) setMatches(rows)
      })
      .catch((caught: unknown) => {
        if (active) {
          setMatches([])
          setError(
            caught instanceof Error
              ? caught.message
              : "No se pudieron consultar los partidos.",
          )
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [reloadKey, selectedDate])

  const orderedMatches = useMemo(
    () =>
      [...matches].sort((left, right) => {
        const timeOrder = (left.kickoff_time ?? "99:99:99").localeCompare(
          right.kickoff_time ?? "99:99:99",
        )
        if (timeOrder !== 0) return timeOrder
        return (
          left.category_name.localeCompare(right.category_name) ||
          left.tournament_name.localeCompare(right.tournament_name) ||
          left.stage_sequence - right.stage_sequence ||
          left.round_number - right.round_number ||
          (left.match_number ?? 0) - (right.match_number ?? 0) ||
          left.id - right.id
        )
      }),
    [matches],
  )

  const changeDate = (offset: number) => {
    setSelectedDate((current) => shiftDate(current, offset))
  }

  return (
    <>
      <div className="app-page-heading">
        <div>
          <span className="app-kicker">AGENDA DE COMPETENCIAS</span>
          <h1>Partidos del día</h1>
          <p>Consulta los encuentros programados de todos los torneos.</p>
        </div>
      </div>

      <section className="app-day-toolbar" aria-label="Consultar fecha de partidos">
        <button
          className="app-button secondary"
          type="button"
          onClick={() => changeDate(-1)}
          aria-label="Consultar día anterior"
        >
          ← Día anterior
        </button>
        <label className="app-field app-day-date">
          <span>Fecha</span>
          <input
            type="date"
            value={selectedDate}
            onChange={(event) => setSelectedDate(event.target.value)}
          />
        </label>
        <button
          className="app-button"
          type="button"
          disabled={!selectedDate || loading}
          onClick={() => setReloadKey((key) => key + 1)}
        >
          {loading ? "Consultando..." : "Consultar fecha"}
        </button>
        <button
          className="app-button secondary"
          type="button"
          onClick={() => changeDate(1)}
          aria-label="Consultar día siguiente"
        >
          Día siguiente →
        </button>
      </section>

      <section className="app-panel app-day-panel">
        <header className="app-panel-head">
          <div>
            <h2>{selectedDate ? readableDate(selectedDate) : "Selecciona una fecha"}</h2>
            <p>
              {loading
                ? "Buscando partidos en todos los torneos..."
                : `${orderedMatches.length} encuentros programados`}
            </p>
          </div>
        </header>

        {error ? (
          <div className="app-day-error" role="alert">
            <strong>No se pudieron cargar los partidos</strong>
            <span>{error}</span>
            <button
              className="app-button secondary"
              type="button"
              onClick={() => setReloadKey((key) => key + 1)}
            >
              Reintentar
            </button>
          </div>
        ) : loading ? (
          <div className="app-loading" role="status">
            Cargando partidos...
          </div>
        ) : orderedMatches.length === 0 ? (
          <div className="app-empty">
            <span className="app-empty-mark">▦</span>
            <strong>No hay partidos programados</strong>
            <p>No se encontraron encuentros para la fecha seleccionada.</p>
          </div>
        ) : (
          <div className="app-day-list">
            {orderedMatches.map((match) => (
              <article className="app-day-match" key={match.id}>
                <div className="app-day-match-meta">
                  <span className="app-day-time">
                    {match.kickoff_time
                      ? match.kickoff_time.slice(0, 5)
                      : "Hora pendiente"}
                  </span>
                  <span className="app-status">{statusLabel(match.status)}</span>
                </div>
                <div className="app-day-context">
                  <span>{match.category_name}</span>
                  <strong>{match.tournament_name}</strong>
                  <small>
                    {[
                      match.stage_name,
                      match.group_name,
                      match.round_name || `Jornada ${match.round_number}`,
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </small>
                </div>
                <div className="app-day-teams">
                  <strong>{match.home_team_name}</strong>
                  <span className="app-day-score">
                    {match.home_score == null || match.away_score == null
                      ? "— : —"
                      : `${match.home_score} : ${match.away_score}`}
                  </span>
                  <strong>{match.away_team_name}</strong>
                </div>
                {match.venue && (
                  <small className="app-day-venue">{match.venue}</small>
                )}
              </article>
            ))}
          </div>
        )}
      </section>
    </>
  )
}
