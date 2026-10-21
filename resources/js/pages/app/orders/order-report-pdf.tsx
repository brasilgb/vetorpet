import { maskMoney } from "@/Utils/mask";
import { Document, Page, Text, View, StyleSheet } from "@react-pdf/renderer";
import moment from "moment";

const styles = StyleSheet.create({
    page: {
        flexDirection: 'column',
        backgroundColor: '#FFFFFF',
        padding: 30,
    },
    header: {
        marginBottom: 20,
        textAlign: 'center',
    },
    title: {
        fontSize: 18,
        fontWeight: 'bold',
    },
    table: {
        display: "flex",
        width: "auto",
        borderStyle: "solid",
        borderWidth: 1,
        borderRightWidth: 0,
        borderBottomWidth: 0,
    },
    tableRow: {
        margin: "auto",
        flexDirection: "row",
    },
    tableColHeader: {
        width: "16.66%",
        borderStyle: "solid",
        borderWidth: 1,
        borderLeftWidth: 0,
        borderTopWidth: 0,
        backgroundColor: '#f2f2f2',
        padding: 5,
    },
    tableCol: {
        width: "16.66%",
        borderStyle: "solid",
        borderWidth: 1,
        borderLeftWidth: 0,
        borderTopWidth: 0,
        padding: 5,
    },
    tableCellHeader: {
        margin: "auto",
        marginTop: 5,
        fontSize: 10,
        fontWeight: 'bold',
    },
    tableCell: {
        margin: "auto",
        marginTop: 5,
        fontSize: 9,
    },
    summary: {
        marginTop: 20,
        padding: 10,
        borderWidth: 1,
        borderColor: '#dddddd',
        borderRadius: 5,
        flexDirection: 'row',
        justifyContent: 'space-around',
    },
    summaryText: {
        fontSize: 10,
    }
});

export default function OrderReportPDF({ data, startDate, endDate, summaryData, sellerName }: any) {
    return (
        <Document>
            <Page size="A4" style={styles.page}>
                <View style={styles.header}>
                    <Text style={styles.title}>Relatório de Pedidos</Text>
                    <Text>Período: {moment(startDate).format('DD/MM/YYYY')} a {moment(endDate).format('DD/MM/YYYY')}</Text>
                    <Text>Vendedor: {sellerName ?? 'Todos'}</Text>
                </View>

                <View style={styles.summary}>
                    <Text style={styles.summaryText}>Flex: {maskMoney(String(summaryData?.flex || '0.00'))}</Text>
                    <Text style={styles.summaryText}>Descontos: {maskMoney(String(summaryData?.discount || '0.00'))}</Text>
                    <Text style={styles.summaryText}>Total: {maskMoney(String(summaryData?.total || '0.00'))}</Text>
                </View>

                <View style={styles.table}>
                    <View style={styles.tableRow}>
                        <View style={styles.tableColHeader}><Text style={styles.tableCellHeader}>Nº Pedido</Text></View>
                        <View style={styles.tableColHeader}><Text style={styles.tableCellHeader}>Cliente</Text></View>
                        <View style={styles.tableColHeader}><Text style={styles.tableCellHeader}>Vendedor</Text></View>
                        <View style={styles.tableColHeader}><Text style={styles.tableCellHeader}>Flex</Text></View>
                        <View style={styles.tableColHeader}><Text style={styles.tableCellHeader}>Desconto</Text></View>
                        <View style={styles.tableColHeader}><Text style={styles.tableCellHeader}>Total</Text></View>
                    </View>
                    {data && data.length > 0 ? (
                        data.map((order: any) => (
                            <View style={styles.tableRow} key={order.id}>
                                <View style={styles.tableCol}><Text style={styles.tableCell}>{order.order_number}</Text></View>
                                <View style={styles.tableCol}><Text style={styles.tableCell}>{order?.customer?.name}</Text></View>
                                <View style={styles.tableCol}><Text style={styles.tableCell}>{order?.user?.name ?? '-'}</Text></View>
                                <View style={styles.tableCol}><Text style={styles.tableCell}>R$ {order.flex}</Text></View>
                                <View style={styles.tableCol}><Text style={styles.tableCell}>R$ {order.discount}</Text></View>
                                <View style={styles.tableCol}><Text style={styles.tableCell}>R$ {order.total}</Text></View>
                            </View>
                        ))
                    ) : (
                        <View style={styles.tableRow}>
                            <View style={{...styles.tableCol, width: '100%', textAlign: 'center'}}>
                                <Text style={styles.tableCell}>Nenhum pedido encontrado para este período.</Text>
                            </View>
                        </View>
                    )}
                </View>
            </Page>
        </Document>
    );
}
