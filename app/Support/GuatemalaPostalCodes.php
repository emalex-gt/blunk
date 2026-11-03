<?php

namespace App\Support;

/**
 * Verified municipal postal codes from Correos de Guatemala departmental lists.
 *
 * The entries are explicit source transcriptions, not INE administrative codes
 * nor generated sequences. A missing entry is intentionally unknown.
 */
final class GuatemalaPostalCodes
{
    public const CORREOS_SOURCE = 'https://correos.gob.gt/CodigosPos/ListaCodigos/';
    public const CELASA_SOURCE = 'https://www.servicios-ws.celasa.com.gt/codigos-postales';

    public static function forLocation(?string $department, ?string $municipality): ?string
    {
        $department = GuatemalaLocations::canonicalDepartment($department);
        $municipality = trim((string) $municipality);

        if (! $department || $municipality === '') {
            return null;
        }

        return self::confirmed()[$department][$municipality] ?? null;
    }

    /** @return array<string, array<string, string>> */
    public static function confirmed(): array
    {
        return [
            'Alta Verapaz' => [
                'Cobán' => '16001', 'Santa Cruz Verapaz' => '16002', 'San Cristóbal Verapaz' => '16003', 'Tactic' => '16004', 'Tamahú' => '16005', 'Tucurú' => '16006', 'Panzós' => '16007', 'Senahú' => '16008', 'San Pedro Carchá' => '16009', 'San Juan Chamelco' => '16010', 'Lanquín' => '16011', 'Cahabón' => '16012', 'Chisec' => '16013', 'Chahal' => '16014', 'Fray Bartolomé de las Casas' => '16015',
            ],
            'Baja Verapaz' => [
                'Salamá' => '15001', 'San Miguel Chicaj' => '15002', 'Rabinal' => '15003', 'Cubulco' => '15004', 'Granados' => '15005', 'El Chol' => '15006', 'San Jerónimo' => '15007', 'Purulhá' => '15008',
            ],
            'Chimaltenango' => [
                'Chimaltenango' => '04001', 'San José Poaquil' => '04002', 'San Martín Jilotepeque' => '04003', 'San Juan Comalapa' => '04004', 'Santa Apolonia' => '04005', 'Tecpán Guatemala' => '04006', 'Patzún' => '04007', 'Pochuta' => '04008', 'Patzicía' => '04009', 'Santa Cruz Balanyá' => '04010', 'Acatenango' => '04011', 'Yepocapa' => '04012', 'San Andrés Itzapa' => '04013', 'Parramos' => '04014', 'Zaragoza' => '04015', 'El Tejar' => '04016',
            ],
            'Chiquimula' => [
                'Chiquimula' => '20001', 'San José La Arada' => '20002', 'San Juan Ermita' => '20003', 'Jocotán' => '20004', 'Camotán' => '20005', 'Olopa' => '20006', 'Esquipulas' => '20007', 'Concepción Las Minas' => '20008', 'Quezaltepeque' => '20009', 'San Jacinto' => '20010', 'Ipala' => '20011',
            ],
            'El Progreso' => [
                'Guastatoya' => '02001', 'Morazán' => '02002', 'San Agustín Acasaguastlán' => '02003', 'San Cristóbal Acasaguastlán' => '02004', 'El Jícaro' => '02005', 'Sansare' => '02006', 'Sanarate' => '02007', 'San Antonio La Paz' => '02008',
            ],
            'Escuintla' => [
                'Escuintla' => '05001', 'Santa Lucía Cotzumalguapa' => '05002', 'La Democracia' => '05003', 'Siquinalá' => '05004', 'Masagua' => '05005', 'Tiquisate' => '05006', 'La Gomera' => '05007', 'San José' => '05009', 'Iztapa' => '05010', 'Palín' => '05011', 'San Vicente Pacaya' => '05012', 'Nueva Concepción' => '05013',
            ],
            'Guatemala' => [
                'Santa Catarina Pinula' => '01051', 'San José Pinula' => '01052', 'San José del Golfo' => '01053', 'Palencia' => '01054', 'Chinautla' => '01055', 'San Pedro Ayampuc' => '01056', 'Mixco' => '01057', 'San Pedro Sacatepéquez' => '01058', 'San Juan Sacatepéquez' => '01059', 'San Raymundo' => '01060', 'Chuarrancho' => '01061', 'Fraijanes' => '01062', 'Amatitlán' => '01063', 'Villa Nueva' => '01064', 'Villa Canales' => '01065', 'San Miguel Petapa' => '01066',
            ],
            'Huehuetenango' => [
                'Huehuetenango' => '13001', 'Chiantla' => '13002', 'Malacatancito' => '13003', 'Cuilco' => '13004', 'Nentón' => '13005', 'San Pedro Necta' => '13006', 'Jacaltenango' => '13007', 'Soloma' => '13008', 'Ixtahuacán' => '13009', 'Santa Bárbara' => '13010', 'La Libertad' => '13011', 'La Democracia' => '13012', 'San Miguel Acatán' => '13013', 'San Rafael La Independencia' => '13014', 'Todos Santos Cuchumatán' => '13015', 'San Juan Atitán' => '13016', 'Santa Eulalia' => '13017', 'San Mateo Ixtatán' => '13018', 'Colotenango' => '13019', 'San Sebastián Huehuetenango' => '13020', 'Tectitán' => '13021', 'Concepción Huista' => '13022', 'San Juan Ixcoy' => '13023', 'San Antonio Huista' => '13024', 'San Sebastián Coatán' => '13025', 'Barillas' => '13026', 'Aguacatán' => '13027', 'San Rafael Petzal' => '13028', 'San Gaspar Ixchil' => '13029', 'Santiago Chimaltenango' => '13030', 'Santa Ana Huista' => '13031',
            ],
            'Izabal' => [
                'Puerto Barrios' => '18001', 'Livingston' => '18002', 'El Estor' => '18003', 'Morales' => '18004', 'Los Amates' => '18005',
            ],
            'Jalapa' => [
                'Jalapa' => '21001', 'San Pedro Pinula' => '21002', 'San Luis Jilotepeque' => '21003', 'San Manuel Chaparrón' => '21004', 'San Carlos Alzatate' => '21005', 'Monjas' => '21006', 'Mataquescuintla' => '21007',
            ],
            'Jutiapa' => [
                'Jutiapa' => '22001', 'El Progreso' => '22002', 'Santa Catarina Mita' => '22003', 'Agua Blanca' => '22004', 'Asunción Mita' => '22005', 'Yupiltepeque' => '22006', 'Atescatempa' => '22007', 'Jerez' => '22008', 'El Adelanto' => '22009', 'Zapotitlán' => '22010', 'Comapa' => '22011', 'Jalpatagua' => '22012', 'Conguaco' => '22013', 'Moyuta' => '22014', 'Pasaco' => '22015', 'San José Acatempa' => '22016', 'Quesada' => '22017',
            ],
            'Petén' => [
                'Flores' => '17001', 'San José' => '17002', 'San Benito' => '17003', 'San Andrés' => '17004', 'La Libertad' => '17005', 'San Francisco' => '17006', 'Santa Ana' => '17007', 'Dolores' => '17008', 'San Luis' => '17009', 'Sayaxché' => '17010', 'Melchor de Mencos' => '17011', 'Poptún' => '17012',
            ],
            'Quetzaltenango' => [
                'Quetzaltenango' => '09001', 'Salcajá' => '09002', 'Olintepeque' => '09003', 'San Carlos Sija' => '09004', 'Sibilia' => '09005', 'Cabricán' => '09006', 'Cajolá' => '09007', 'San Miguel Sigüilá' => '09008', 'Ostuncalco' => '09009', 'San Mateo' => '09010', 'Concepción Chiquirichapa' => '09011', 'San Martín Sacatepéquez' => '09012', 'Almolonga' => '09013', 'Cantel' => '09014', 'Zunil' => '09016', 'Colomba' => '09017', 'San Francisco La Unión' => '09018', 'El Palmar' => '09019', 'Coatepeque' => '09020', 'Génova' => '09021', 'Flores Costa Cuca' => '09022', 'La Esperanza' => '09023', 'Palestina de Los Altos' => '09024',
            ],
            'El Quiche' => [
                'Santa Cruz del Quiché' => '14001', 'Chinique' => '14003', 'Zacualpa' => '14004', 'Chajul' => '14005', 'Chichicastenango' => '14006', 'Patzité' => '14007', 'San Antonio Ilotenango' => '14008', 'San Pedro Jocopilas' => '14009', 'Cunén' => '14010', 'San Juan Cotzal' => '14011', 'Joyabaj' => '14012', 'Nebaj' => '14013', 'San Andrés Sajcabajá' => '14014', 'Uspantán' => '14015', 'Sacapulas' => '14016', 'San Bartolomé Jocotenango' => '14017', 'Canillá' => '14018', 'Ixcán' => '14019', 'Chicamán' => '14020', 'Pachalum' => '14022',
            ],
            'Retalhuleu' => [
                'Retalhuleu' => '11001', 'San Sebastián' => '11002', 'Santa Cruz Muluá' => '11003', 'San Martín Zapotitlán' => '11004', 'San Felipe' => '11005', 'San Andrés Villa Seca' => '11006', 'Champerico' => '11007', 'Nuevo San Carlos' => '11008', 'El Asintal' => '11009',
            ],
            'Sacatepéquez' => [
                'Antigua Guatemala' => '03001', 'Jocotenango' => '03002', 'Pastores' => '03003', 'Sumpango' => '03004', 'Santo Domingo Xenacoj' => '03005', 'Santiago Sacatepéquez' => '03006', 'San Bartolomé Milpas Altas' => '03007', 'San Lucas Sacatepéquez' => '03008', 'Santa Lucía Milpas Altas' => '03009', 'Magdalena Milpas Altas' => '03010', 'Santa María de Jesús' => '03011', 'Ciudad Vieja' => '03012', 'San Miguel Dueñas' => '03013', 'Alotenango' => '03014', 'San Antonio Aguas Calientes' => '03015', 'Santa Catarina Barahona' => '03016',
            ],
            'San Marcos' => [
                'San Marcos' => '12001', 'San Pedro Sacatepéquez' => '12002', 'San Antonio Sacatepéquez' => '12003', 'Comitancillo' => '12004', 'San Miguel Ixtahuacán' => '12005', 'Concepción Tutuapa' => '12006', 'Sibinal' => '12008', 'Tajumulco' => '12009', 'Tejutla' => '12010', 'San Rafael Pie de la Cuesta' => '12011', 'Nuevo Progreso' => '12012', 'El Tumbador' => '12013', 'Malacatán' => '12015', 'Catarina' => '12016', 'Ocós' => '12018', 'San Pablo' => '12019', 'El Quetzal' => '12020', 'La Reforma' => '12021', 'Pajapita' => '12022', 'Ixchiguán' => '12023', 'Sipacapa' => '12026', 'Esquipulas Palo Gordo' => '12027', 'Río Blanco' => '12028', 'San Lorenzo' => '12029',
            ],
            'Santa Rosa' => [
                'Cuilapa' => '06001', 'Barberena' => '06002', 'Santa Rosa de Lima' => '06003', 'Casillas' => '06004', 'San Rafael Las Flores' => '06005', 'Oratorio' => '06006', 'San Juan Tecuaco' => '06007', 'Chiquimulilla' => '06008', 'Taxisco' => '06009', 'Santa María Ixhuatán' => '06010', 'Guazacapán' => '06011', 'Santa Cruz Naranjo' => '06012', 'Pueblo Nuevo Viñas' => '06013', 'Nueva Santa Rosa' => '06014',
            ],
            'Sololá' => [
                'Sololá' => '07001', 'San José Chacayá' => '07002', 'Santa María Visitación' => '07003', 'Santa Lucía Utatlán' => '07004', 'Nahualá' => '07005', 'Santa Catarina Ixtahuacán' => '07006', 'Santa Clara La Laguna' => '07007', 'Concepción' => '07008', 'San Andrés Semetabaj' => '07009', 'Panajachel' => '07010', 'Santa Catarina Palopó' => '07011', 'San Antonio Palopó' => '07012', 'San Lucas Tolimán' => '07013', 'Santa Cruz La Laguna' => '07014', 'San Pablo La Laguna' => '07015', 'San Marcos La Laguna' => '07016', 'San Juan La Laguna' => '07017', 'San Pedro La Laguna' => '07018', 'Santiago Atitlán' => '07019',
            ],
            'Suchitepéquez' => [
                'Mazatenango' => '10001', 'Cuyotenango' => '10002', 'San Francisco Zapotitlán' => '10003', 'San Bernardino' => '10004', 'San José El Ídolo' => '10005', 'Santo Domingo Suchitepéquez' => '10006', 'San Lorenzo' => '10007', 'Samayac' => '10008', 'San Pablo Jocopilas' => '10009', 'San Antonio Suchitepéquez' => '10010', 'San Miguel Panán' => '10011', 'San Gabriel' => '10012', 'Chicacao' => '10013', 'Patulul' => '10014', 'Santa Bárbara' => '10015', 'San Juan Bautista' => '10016', 'Santo Tomás La Unión' => '10017', 'Zunilito' => '10018', 'Pueblo Nuevo' => '10019', 'Río Bravo' => '10020',
            ],
            'Totonicapán' => [
                'Totonicapán' => '08001', 'San Cristóbal Totonicapán' => '08002', 'San Francisco El Alto' => '08003', 'San Andrés Xecul' => '08004', 'Momostenango' => '08005', 'Santa María Chiquimula' => '08006', 'Santa Lucía La Reforma' => '08007', 'San Bartolo' => '08008',
            ],
            'Zacapa' => [
                'Zacapa' => '19001', 'Estanzuela' => '19002', 'Río Hondo' => '19003', 'Gualán' => '19004', 'Teculután' => '19005', 'Usumatlán' => '19006', 'Cabañas' => '19007', 'San Diego' => '19008', 'La Unión' => '19009', 'Huité' => '19010', 'San Jorge' => '19013',
            ],
        ];
    }

    /** @return array<string, array<string, array<int, string>>> */
    public static function unresolved(): array
    {
        return [
            'Alta Verapaz' => [
                'Santa Catalina La Tinta' => ['16016 (LA TINTA (PANZOS))'],
                'Raxruhá' => ['16026 (RAXRUHA (CHISEC))'],
            ],
            'Escuintla' => ['Guanagazapa' => ['05015 (BRITO (GUANAGAZAPA))']],
            'Guatemala' => ['Guatemala' => ['01000 (municipio)', '01001–01019, 01021 (zonas de la capital)']],
            'Huehuetenango' => ['Unión Cantinil' => []],
            'Petén' => [
                'El Chal' => ['17020 (EL CHAL (DOLORES))'],
                'Las Cruces' => ['17027 (LAS CRUCES (LA LIBERTAD))'],
            ],
            'Quetzaltenango' => ['Huitán' => ['09015 (HUITLA)']],
            'El Quiche' => ['Chiché' => ['14002 (QUICHE)']],
            'San Marcos' => [
                'Tacaná' => ['12007 (TANACA)'],
                'Ayutla' => ['12017 (TOCUN UMAN)'],
                'San José El Rodeo' => ['12014 (EL RODEO)'],
                'San José Ojetenam' => ['12024 (SAN JOSE OJOTEPAN)'],
                'San Cristóbal Cucho' => ['12025 (SAN CRITOBAL CUCHO)'],
                'La Blanca' => ['12039 (LA BLANCA (OCOS))'],
            ],
        ];
    }
}
