import { useState } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import { PortalShell } from "@/components/PortalShell";
import { TextField } from "@/components/Field";
import { Button } from "@/components/Button";
import { BuildingPicker, type Building } from "@/components/BuildingPicker";

interface Duplicate {
    enterprise_id: number;
    trading_name: string;
    distance_m: number;
    similarity: number;
}

interface Place {
    latitude: number;
    longitude: number;
    ward: string | null;
    lga: string | null;
    state: string | null;
    covered: boolean;
    footprintId: number | null;
}

interface Props {
    step: string;
    answers: { tradingName: string; structureType: string; phone: string };
    place: Place | null;
    buildings: Building[];
    duplicates: Duplicate[];
    structureTypes: { value: string; label: string }[];
    party: { code: string | null; displayName: string | null };
}

/**
 * Adding a business no officer has reached.
 *
 * Three steps, each an ordinary form post, each saved on the server before the
 * next one loads. The audience is on a handset on mobile data, often standing
 * in the shop being described, and a connection that comes and goes must cost
 * a retry rather than the whole form.
 *
 * No progress bar. A bar is a promise about how much is left that a three step
 * form does not need to make, and it is the first thing that gets stretched
 * when a step is added. The step is said in words instead.
 */
export default function RegisterBusiness({
    step,
    answers,
    place,
    buildings,
    duplicates,
    structureTypes,
    party,
}: Props) {
    return (
        <PortalShell accountName={party.displayName} width="form">
            <Head title="Add your business" />

            <div className="mt-10 mb-8">
                <p className="text-label font-semibold tracking-[0.14em] text-gold uppercase">
                    {step === "name"
                        ? "Step one of three"
                        : step === "place"
                          ? "Step two of three"
                          : "Step three of three"}
                </p>
                <h1 className="mt-1 font-display text-display-l text-ink">
                    {step === "name"
                        ? "Add your business"
                        : step === "place"
                          ? "Where is it?"
                          : "Check and finish"}
                </h1>
            </div>

            {step === "name" && (
                <NameStep answers={answers} structureTypes={structureTypes} />
            )}
            {step === "place" && (
                <PlaceStep place={place} buildings={buildings} />
            )}
            {step === "confirm" && place !== null && (
                <ConfirmStep
                    answers={answers}
                    place={place}
                    duplicates={duplicates}
                />
            )}
        </PortalShell>
    );
}

function NameStep({
    answers,
    structureTypes,
}: {
    answers: Props["answers"];
    structureTypes: Props["structureTypes"];
}) {
    const form = useForm({
        trading_name: answers.tradingName,
        structure_type: answers.structureType,
    });

    return (
        <form
            className="flex flex-col gap-6"
            onSubmit={(e) => {
                e.preventDefault();
                form.post("/portal/register-business/name");
            }}
        >
            <TextField
                label="The name on your signage"
                value={form.data.trading_name}
                onChange={(e) => {
                    form.setData("trading_name", e.target.value);
                }}
                placeholder="Mama Ngozi Provisions"
                size="field"
                autoFocus
                {...(form.errors.trading_name !== undefined && {
                    error: form.errors.trading_name,
                })}
            />

            <fieldset className="flex flex-col gap-2">
                <legend className="mb-2 text-label font-semibold tracking-[0.12em] text-muted uppercase">
                    Where do you trade from?
                </legend>
                {structureTypes.map((type) => (
                    <label
                        key={type.value}
                        className="flex min-h-touch items-center gap-3 rounded-sm border border-rule px-4 py-2.5 text-body text-ink has-checked:border-gold has-checked:bg-raised"
                    >
                        <input
                            type="radio"
                            name="structure_type"
                            value={type.value}
                            checked={form.data.structure_type === type.value}
                            onChange={() => {
                                form.setData("structure_type", type.value);
                            }}
                        />
                        {type.label}
                    </label>
                ))}
            </fieldset>

            <Button
                type="submit"
                variant="primary"
                size="field-primary"
                fullWidth
                busy={form.processing}
            >
                Next
            </Button>
        </form>
    );
}

/**
 * The step that cannot be skipped.
 *
 * A business with no location has nothing anybody could go and look at, and so
 * can never rise above listed however much it would like to pay. That is the
 * rule the whole platform rests on, so it is said here rather than enforced
 * silently by a disabled button.
 */
function PlaceStep({
    place,
    buildings,
}: {
    place: Place | null;
    buildings: Building[];
}) {
    const [locating, setLocating] = useState(false);
    const [denied, setDenied] = useState<string | null>(null);
    const [chosen, setChosen] = useState<number | null>(
        place?.footprintId ?? null,
    );

    const form = useForm({
        latitude: place?.latitude ?? 0,
        longitude: place?.longitude ?? 0,
        accuracy_m: null as number | null,
        external_footprint_id: null as number | null,
        confirm: false,
    });

    function locate() {
        setLocating(true);
        setDenied(null);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                form.transform((data) => ({
                    ...data,
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy_m: position.coords.accuracy,
                    external_footprint_id: null,
                    // Reading a position is not answering which building it is.
                    confirm: false,
                }));
                form.post("/portal/register-business/place", {
                    onFinish: () => {
                        setLocating(false);
                    },
                });
            },
            (error) => {
                setLocating(false);
                setDenied(
                    error.code === error.PERMISSION_DENIED
                        ? "Your phone did not allow us to read your location. Turn location on for this site and try again."
                        : "We could not read your location. Try again, or move somewhere with a clearer view of the sky.",
                );
            },
            { enableHighAccuracy: true, timeout: 20_000, maximumAge: 0 },
        );
    }

    if (place === null) {
        return (
            <div className="flex flex-col gap-6">
                <p className="text-body text-ink">
                    We need to know where your business is. Not an address: the
                    actual spot, so an officer could stand in front of it.
                </p>
                <p className="text-body text-muted">
                    A business without a location can be listed and nothing
                    more. Everything above that is established by somebody going
                    there.
                </p>

                {denied !== null && (
                    <p className="border-l-2 border-alert bg-raised px-4 py-3 text-body text-ink">
                        {denied}
                    </p>
                )}

                <Button
                    variant="primary"
                    size="field-primary"
                    fullWidth
                    busy={locating}
                    onClick={locate}
                >
                    Use where I am now
                </Button>

                <p className="text-label text-faint">
                    Stand at the shop when you press this. You can correct which
                    building it is on the next screen.
                </p>
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-6">
            {!place.covered && (
                <p className="border-l-2 border-alert bg-raised px-4 py-3 text-body text-ink">
                    That location is outside the areas we cover, so we cannot
                    list it yet.
                </p>
            )}

            <div>
                <p className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                    We placed you in
                </p>
                <p className="mt-1 text-body text-ink">
                    {[place.ward, place.lga, place.state]
                        .filter(Boolean)
                        .join(", ")}
                </p>
                <p className="mt-1 text-label text-faint">
                    Worked out from your position, not from anything you typed.
                </p>
            </div>

            {buildings.length > 0 ? (
                <div className="flex flex-col gap-3">
                    <p className="text-body text-ink">
                        Which of these is your building?
                    </p>
                    <BuildingPicker
                        buildings={buildings}
                        selected={chosen}
                        onSelect={setChosen}
                    />
                </div>
            ) : (
                <p className="text-body text-muted">
                    We have no building outlines here, so we will record the
                    exact spot you are standing on.
                </p>
            )}

            <div className="flex flex-col gap-3">
                <Button
                    variant="primary"
                    size="field-primary"
                    fullWidth
                    busy={form.processing}
                    onClick={() => {
                        form.transform((data) => ({
                            ...data,
                            latitude: place.latitude,
                            longitude: place.longitude,
                            external_footprint_id: chosen,
                            confirm: true,
                        }));
                        form.post("/portal/register-business/place");
                    }}
                >
                    {chosen === null ? "Use this spot" : "This is my building"}
                </Button>

                <Button variant="quiet" size="field" fullWidth onClick={locate}>
                    Read my location again
                </Button>
            </div>
        </div>
    );
}

function ConfirmStep({
    answers,
    place,
    duplicates,
}: {
    answers: Props["answers"];
    place: Place;
    duplicates: Duplicate[];
}) {
    const form = useForm({ phone: answers.phone, sector_code: "" });
    const back = useForm({});

    // The location error comes from the submit handler rather than from a field
    // on this form, so it is read off the page's errors rather than the form's.
    // It is the one failure that cannot be fixed on this step, which is why it
    // is worth surfacing here rather than swallowing.
    const pageErrors = usePage().props.errors as
        Record<string, string> | undefined;
    const locationError = pageErrors?.location ?? null;

    return (
        <div className="flex flex-col gap-8">
            {/* Shown before the button, not after the record exists. Somebody
                about to create a second listing for a business already on the
                register should be offered the first one instead. */}
            {duplicates.length > 0 && (
                <section className="border-l-2 border-gold bg-raised py-4 pr-4 pl-5">
                    <h2 className="font-display text-display-s text-ink">
                        This may already be on the register
                    </h2>
                    <p className="mt-2 text-body text-ink">
                        An officer recorded{" "}
                        {duplicates.length === 1 ? "a business" : "businesses"}{" "}
                        with a very similar name, at this spot. Claiming the
                        existing record keeps everything it already establishes.
                    </p>
                    <ul className="mt-3 flex flex-col gap-2">
                        {duplicates.map((duplicate) => (
                            <li
                                key={duplicate.enterprise_id}
                                className="text-body"
                            >
                                <span className="text-ink">
                                    {duplicate.trading_name}
                                </span>
                                <span className="numeric-mono text-label text-faint">
                                    {" "}
                                    · {duplicate.distance_m} m away
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="mt-4">
                        <Link
                            href={`/portal/claim?q=${encodeURIComponent(answers.tradingName)}`}
                            className="text-body text-gold underline underline-offset-4"
                        >
                            Claim the existing listing instead
                        </Link>
                    </p>
                </section>
            )}

            <dl className="flex flex-col rounded-sm bg-sunken px-4 py-1">
                {[
                    ["Name", answers.tradingName],
                    [
                        "Where",
                        [place.ward, place.lga].filter(Boolean).join(", "),
                    ],
                    [
                        "Building",
                        place.footprintId === null
                            ? "the spot you marked"
                            : "the building you chose",
                    ],
                ].map(([label, value]) => (
                    <div
                        key={label}
                        className="flex items-baseline justify-between gap-4 border-b border-rule py-2.5 last:border-b-0"
                    >
                        <dt className="text-label font-semibold tracking-[0.12em] text-muted uppercase">
                            {label}
                        </dt>
                        <dd className="text-ui text-ink">{value}</dd>
                    </div>
                ))}
            </dl>

            <form
                className="flex flex-col gap-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/portal/register-business");
                }}
            >
                <TextField
                    label="A phone number for the business"
                    hint="Optional. It is how we reach you about a verification, and how you prove this listing is yours if you sign in from a new phone."
                    value={form.data.phone}
                    onChange={(e) => {
                        form.setData("phone", e.target.value);
                    }}
                    inputMode="tel"
                    size="field"
                    {...(form.errors.phone !== undefined && {
                        error: form.errors.phone,
                    })}
                />

                {locationError !== null && (
                    <p className="text-ui text-alert">{locationError}</p>
                )}

                {/* What this does and does not establish, before the button
                    rather than in a confirmation afterwards. */}
                <p className="text-body text-muted">
                    This puts your business on the register as listed. It does
                    not say anybody has been to see it: that takes a visit, and
                    you can order one once this is done.
                </p>

                <Button
                    type="submit"
                    variant="primary"
                    size="field-primary"
                    fullWidth
                    busy={form.processing}
                >
                    Add my business
                </Button>
            </form>

            <Button
                variant="quiet"
                size="field"
                fullWidth
                busy={back.processing}
                onClick={() => {
                    back.post("/portal/register-business/back");
                }}
            >
                Back
            </Button>
        </div>
    );
}
