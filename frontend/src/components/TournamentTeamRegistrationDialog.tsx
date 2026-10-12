import { useMemo, useState, type FormEvent } from "react"

import type { Team } from "../lib/api"

export default function TournamentTeamRegistrationDialog({
  tournamentName,
  teams,
  registeredTeamIds,
  saving,
  onClose,
  onSave,
}: {
  tournamentName: string
  teams: Team[]
  registeredTeamIds: Set<number>
  saving: boolean
  onClose: () => void
  onSave: (teamIds: number[]) => Promise<void>
}) {
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [search, setSearch] = useState("")
  const [error, setError] = useState("")

  const visibleTeams = useMemo(
    () =>
      teams
        .filter((team) => team.is_active)
        .filter((team) =>
          `${team.name} ${team.short_name ?? ""}`
            .toLowerCase()
            .includes(search.trim().toLowerCase()),
        )
        .sort((left, right) => left.name.localeCompare(right.name, "es")),
    [search, teams],
  )
  const selectableTeams = visibleTeams.filter(
    (team) => !registeredTeamIds.has(team.id),
  )
  const selectedCount = selectedIds.filter(
    (id) => !registeredTeamIds.has(id),
  ).length

  const toggleTeam = (teamId: number) => {
    setSelectedIds((current) =>
      current.includes(teamId)
        ? current.filter((id) => id !== teamId)
        : [...current, teamId],
    )
  }

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError("")
    const pendingIds = selectedIds.filter((id) => !registeredTeamIds.has(id))
    if (pendingIds.length === 0) {
      setError("Selecciona al menos un equipo disponible para inscribir.")
      return
    }
    try {
      await onSave(pendingIds)
    } catch (caught) {
      setError(
        caught instanceof Error
          ? caught.message
          : "No se pudieron inscribir los equipos seleccionados.",
      )
    }
  }

  return (
    <div
      className="app-modal-backdrop"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !saving) onClose()
      }}
    >
      <form
        className="app-modal app-team-registration-modal"
        onSubmit={submit}
        onMouseDown={(event) => event.stopPropagation()}
      >
        <div className="app-modal-head">
          <div>
            <span className="app-kicker">INSCRIPCIÓN AL TORNEO</span>
            <h2>Seleccionar equipos</h2>
            <p className="app-team-registration-description">
              Torneo: <strong>{tournamentName}</strong>. Selecciona todos los
              equipos que quieres inscribir y guárdalos con un solo clic.
            </p>
          </div>
          <button
            className="app-close"
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
            disabled={saving}
          >
            ×
          </button>
        </div>

        <label className="app-field app-team-search">
          <span>Buscar en el banco de equipos</span>
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Nombre o abreviatura"
            autoFocus
          />
        </label>

        {visibleTeams.length > 0 ? (
          <div className="app-team-registration-list">
            {visibleTeams.map((team) => {
              const isRegistered = registeredTeamIds.has(team.id)
              const isSelected = selectedIds.includes(team.id)
              return (
                <label
                  className={`app-team-registration-option${isRegistered ? " registered" : ""}`}
                  key={team.id}
                >
                  <input
                    type="checkbox"
                    checked={isRegistered || isSelected}
                    disabled={isRegistered || saving}
                    onChange={() => toggleTeam(team.id)}
                  />
                  {team.logo_url ? (
                    <img src={team.logo_url} alt="" />
                  ) : (
                    <span className="app-crest">
                      {team.name
                        .split(/\s+/)
                        .filter(Boolean)
                        .slice(0, 2)
                        .map((part) => part[0]?.toUpperCase())
                        .join("")}
                    </span>
                  )}
                  <span className="app-team-registration-name">
                    <strong>{team.name}</strong>
                    {team.short_name && <small>{team.short_name}</small>}
                  </span>
                  {isRegistered && (
                    <span className="app-team-registered-tag">Inscrito</span>
                  )}
                </label>
              )
            })}
          </div>
        ) : (
          <div className="app-empty app-team-registration-empty">
            <strong>
              {teams.some((team) => team.is_active)
                ? "No hay equipos que coincidan"
                : "No hay equipos disponibles"}
            </strong>
            <p>
              {teams.some((team) => team.is_active)
                ? "Prueba con otro nombre o abreviatura."
                : "Registra equipos en el banco antes de inscribirlos."}
            </p>
          </div>
        )}

        {error && (
          <div className="app-inline-error" role="alert">
            {error}
          </div>
        )}

        <footer className="app-modal-actions app-team-registration-actions">
          <span>{selectedCount} equipo(s) seleccionado(s)</span>
          <button
            className="app-button secondary"
            type="button"
            onClick={onClose}
            disabled={saving}
          >
            Cancelar
          </button>
          <button
            className="app-button"
            type="submit"
            disabled={saving || selectedCount === 0 || selectableTeams.length === 0}
          >
            {saving ? "Inscribiendo equipos..." : "Guardar equipos"}
          </button>
        </footer>
      </form>
    </div>
  )
}
