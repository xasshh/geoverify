import { Head } from '@inertiajs/react';

interface HealthProps {
    postgis: string;
    h3: string;
    cellsOverAbuja: number;
}

export default function Health({ postgis, h3, cellsOverAbuja }: HealthProps) {
    return (
        <>
            <Head title="Health" />
            <main className="health">
                <h1>GeoVerify</h1>
                <dl>
                    <dt>PostGIS</dt>
                    <dd>{postgis}</dd>
                    <dt>H3</dt>
                    <dd>{h3}</dd>
                    <dt>Res 9 cells over test polygon</dt>
                    <dd>{cellsOverAbuja}</dd>
                </dl>
            </main>
        </>
    );
}
